<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const REASONS = ['order', 'order_cancelled', 'manual', 'import'];

    private const NEW_REASONS = ['restock', 'adjustment', 'loss', 'return', 'order_expired', 'counter_sale'];

    /**
     * Stock (phase 1) :
     * - stock_movements : nouveaux motifs (réception, inventaire, perte, retour, commande expirée,
     *   vente au comptoir), note, coût unitaire, stock après le mouvement, et business_id pour le
     *   journal de l'entreprise. La suppression d'une variante n'efface plus son historique
     *   (variant_id passe à null au lieu de supprimer le mouvement) ;
     * - products et product_variants : archivage (deleted_at) au lieu de la suppression ;
     * - product_variants : business_id et options_key, pour l'unicité des options par article et du
     *   SKU par entreprise (index partiels, hors variantes archivées).
     *
     * Données existantes : aucune n'est supprimée. Deux variantes actives aux mêmes options, ou deux SKU
     * identiques dans une entreprise, empêcheraient la création des index : les doublons sont signalés
     * dans la sortie de la migration ; la clé d'options du doublon est rendue distincte et son SKU
     * suffixé (« -DOUBLON-2 »), visible et corrigible par le gérant.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUuid('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->integer('balance_after')->nullable();
        });

        DB::table('stock_movements')->orderBy('id')->chunkById(500, function ($movements) {
            $products = DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereIn('product_variants.id', $movements->pluck('variant_id')->unique()->all())
                ->pluck('products.business_id', 'product_variants.id');

            foreach ($movements as $movement) {
                DB::table('stock_movements')->where('id', $movement->id)->update(['business_id' => $products[$movement->variant_id] ?? null]);
            }
        });

        $this->setReasons([...self::REASONS, ...self::NEW_REASONS]);

        // Plus de suppression en cascade : le mouvement reste, sans variante.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable()->change();
            $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->index(['business_id', 'created_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignUuid('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('options_key', 64)->nullable();
        });

        $this->backfillVariants();

        DB::statement('CREATE UNIQUE INDEX product_variants_options_unique ON product_variants (product_id, options_key) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX product_variants_sku_unique ON product_variants (business_id, LOWER(sku)) WHERE sku IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_variants_options_unique');
        DB::statement('DROP INDEX IF EXISTS product_variants_sku_unique');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn(['options_key', 'deleted_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        // Les mouvements sans variante (variante supprimée depuis) ne peuvent pas revenir à une colonne obligatoire.
        $orphans = DB::table('stock_movements')->whereNull('variant_id')->count();
        if ($orphans > 0) {
            throw new RuntimeException("{$orphans} mouvement(s) de stock n'ont plus de variante : retour arrière impossible sans les perdre.");
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'created_at']);
            $table->dropForeign(['variant_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->uuid('variant_id')->nullable(false)->change();
            $table->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
        });

        // Motifs ajoutés : ramenés au plus proche des anciens (saisie du gérant ou commande annulée).
        DB::table('stock_movements')->whereIn('reason', ['restock', 'adjustment', 'loss', 'return'])->update(['reason' => 'manual']);
        DB::table('stock_movements')->where('reason', 'order_expired')->update(['reason' => 'order_cancelled']);
        DB::table('stock_movements')->where('reason', 'counter_sale')->update(['reason' => 'order']);
        $this->setReasons(self::REASONS);

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn(['note', 'unit_cost', 'balance_after']);
        });
    }

    private function backfillVariants(): void
    {
        $seenOptions = [];
        $seenSkus = [];

        DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select(['product_variants.id', 'product_variants.product_id', 'product_variants.options', 'product_variants.sku', 'products.business_id'])
            ->orderBy('product_variants.created_at')
            ->orderBy('product_variants.id')
            ->chunk(500, function ($variants) use (&$seenOptions, &$seenSkus) {
                foreach ($variants as $variant) {
                    $key = self::optionsKey((array) json_decode((string) $variant->options, true));
                    $update = ['business_id' => $variant->business_id, 'options_key' => $key];

                    if (isset($seenOptions[$variant->product_id][$key])) {
                        $update['options_key'] = substr(hash('sha256', $key.'#'.$variant->id), 0, 64);
                        echo "  Variante {$variant->id} : mêmes options qu'une autre variante de l'article {$variant->product_id} (gardée, à fusionner par le gérant).\n";
                    }
                    $seenOptions[$variant->product_id][$key] = true;

                    $sku = $variant->sku !== null ? mb_strtolower(trim($variant->sku)) : '';
                    if ($sku !== '') {
                        $count = ($seenSkus[$variant->business_id][$sku] ?? 0) + 1;
                        $seenSkus[$variant->business_id][$sku] = $count;
                        if ($count > 1) {
                            $update['sku'] = Str::limit($variant->sku, 50, '').'-DOUBLON-'.$count;
                            echo "  Variante {$variant->id} : SKU « {$variant->sku} » déjà utilisé dans l'entreprise, renommé « {$update['sku']} ».\n";
                        }
                    }

                    DB::table('product_variants')->where('id', $variant->id)->update($update);
                }
            });
    }

    /** Même calcul que App\Services\Catalog\CatalogService::optionsKey (figé ici pour la reprise). */
    private static function optionsKey(array $options): string
    {
        $normalized = [];
        foreach ($options as $name => $value) {
            $name = trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii((string) $name))));
            $value = trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii(is_scalar($value) ? (string) $value : ''))));
            if ($name !== '' && $value !== '') {
                $normalized[$name] = $value;
            }
        }
        ksort($normalized);

        return hash('sha256', (string) json_encode($normalized));
    }

    /**
     * @param  list<string>  $reasons
     */
    private function setReasons(array $reasons): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(', ', array_map(fn (string $reason) => "'{$reason}'", $reasons));

            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_reason_check');
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_reason_check CHECK (reason IN ({$values}))");

            return;
        }

        Schema::table('stock_movements', function (Blueprint $table) use ($reasons) {
            $table->enum('reason', $reasons)->change();
        });
    }
};
