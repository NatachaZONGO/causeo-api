<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Ventes (phase 1) :
     * - order_items : lignes de commande en table, avec une copie figée du nom, des options et des
     *   prix (prix du catalogue et prix pratiqué). orders.items (JSON) reste écrit en copie de secours ;
     * - order_payments : chaque versement (espèces, Orange Money, Moov Money…), dont se déduisent
     *   orders.amount_paid et orders.payment_status ;
     * - orders : origine (source), téléphone facultatif (vente au comptoir), dates de chaque statut,
     *   motif d'annulation, auteur de la saisie, signal « preuve de paiement, pas d'expiration » prévenu.
     *
     * Reprise : chaque commande existante reçoit ses lignes à partir de orders.items. Une commande
     * « paid » ou « delivered » est considérée payée : un versement du montant total est créé
     * (note « Reprise »), daté de sa dernière modification. Aucune donnée n'est modifiée ni supprimée.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('source', ['whatsapp_ai', 'counter', 'manual'])->default('whatsapp_ai');
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 100)->nullable();
            // Commande non confirmée, pas expirée car le client a envoyé une preuve de paiement : gérant prévenu.
            $table->timestamp('expiry_notified_at')->nullable();
            // Gérant qui a saisi la vente (comptoir) ; null pour une commande de l'assistant.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['business_id', 'created_at']);
            // Phase 2 (crédits clients) : ventes non soldées d'un client.
            $table->index(['business_id', 'payment_status']);
            $table->index(['business_id', 'customer_phone']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_phone')->nullable()->change();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            // Copie figée au moment de la vente : le catalogue peut changer ensuite.
            $table->string('name');
            $table->string('options')->nullable();
            $table->unsignedInteger('quantity');
            // Prix du catalogue (suppléments compris) ; null pour une ligne hors catalogue.
            $table->decimal('catalog_unit_price', 12, 2)->nullable();
            // Prix réellement pratiqué (négocié, remisé).
            $table->decimal('unit_price', 12, 2);
            $table->json('extras')->nullable();
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['order_id', 'position']);
            $table->index('variant_id');
            $table->index('product_id');
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['cash', 'orange_money', 'moov_money', 'other']);
            $table->timestamp('paid_at');
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('order_id');
            $table->index(['business_id', 'paid_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // RLS sans politique : aucun accès via l'API Supabase (anon / authenticated).
            foreach (['order_items', 'order_payments'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            }
        }

        $this->backfill();
    }

    /**
     * Retour arrière sans perte : les lignes et les versements restent dans orders.items et
     * orders.payment_method. Un téléphone vide (vente au comptoir) devient une chaîne vide.
     */
    public function down(): void
    {
        DB::table('orders')->whereNull('customer_phone')->update(['customer_phone' => '']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_phone')->nullable(false)->change();
        });

        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_items');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'created_at']);
            $table->dropIndex(['business_id', 'payment_status']);
            $table->dropIndex(['business_id', 'customer_phone']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'source', 'amount_paid', 'payment_status', 'confirmed_at', 'paid_at', 'delivered_at',
                'cancelled_at', 'cancellation_reason', 'expiry_notified_at',
            ]);
        });
    }

    private function backfill(): void
    {
        $variantProducts = Schema::hasTable('product_variants')
            ? DB::table('product_variants')->pluck('product_id', 'id')->all()
            : [];

        DB::table('orders')->orderBy('id')->chunk(200, function ($orders) use ($variantProducts) {
            foreach ($orders as $order) {
                $items = json_decode((string) $order->items, true);
                $now = now();
                $rows = [];

                foreach (is_array($items) ? array_values($items) : [] as $position => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $quantity = max(1, (int) ($item['quantity'] ?? 1));
                    $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
                    $variantId = isset($item['variant_id'], $variantProducts[$item['variant_id']]) ? $item['variant_id'] : null;
                    $options = $item['options'] ?? null;
                    if (is_array($options)) {
                        $options = implode(', ', array_filter(array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : '', $options)));
                    }

                    $rows[] = [
                        'order_id' => $order->id,
                        'product_id' => $variantId !== null ? $variantProducts[$variantId] : null,
                        'variant_id' => $variantId,
                        'position' => $position,
                        'name' => Str::limit(trim((string) ($item['name'] ?? '')) ?: 'Article', 250, ''),
                        'options' => is_scalar($options) && trim((string) $options) !== '' ? Str::limit(trim((string) $options), 250, '') : null,
                        'quantity' => $quantity,
                        // Avant la négociation des prix, le prix pratiqué était celui du catalogue.
                        'catalog_unit_price' => $variantId !== null ? $unitPrice : null,
                        'unit_price' => $unitPrice,
                        'extras' => ! empty($item['extras']) ? json_encode($item['extras'], JSON_UNESCAPED_UNICODE) : null,
                        'line_total' => round($quantity * $unitPrice, 2),
                        'created_at' => $order->created_at ?? $now,
                        'updated_at' => $order->created_at ?? $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('order_items')->insert($rows);
                }

                $at = $order->updated_at ?? $order->created_at ?? $now;
                $dates = match ($order->status) {
                    'confirmed' => ['confirmed_at' => $at],
                    'paid' => ['paid_at' => $at],
                    'delivered' => ['delivered_at' => $at],
                    'cancelled' => ['cancelled_at' => $at],
                    default => [],
                };

                $total = round((float) $order->total_amount, 2);
                if (in_array($order->status, ['paid', 'delivered'], true)) {
                    if ($total > 0) {
                        DB::table('order_payments')->insert([
                            'business_id' => $order->business_id,
                            'order_id' => $order->id,
                            'amount' => $total,
                            'method' => self::method($order->payment_method),
                            'paid_at' => $at,
                            'note' => 'Reprise : commande marquée '.($order->status === 'paid' ? 'payée' : 'livrée').' avant le suivi des paiements',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                    $dates += ['amount_paid' => $total, 'payment_status' => 'paid', 'paid_at' => $at];
                }

                if ($dates !== []) {
                    DB::table('orders')->where('id', $order->id)->update($dates);
                }
            }
        });
    }

    /** Moyen de paiement à partir du texte libre de l'assistant (« Orange Money », « à la livraison »…). */
    public static function method(?string $text): string
    {
        $text = Str::lower(Str::ascii((string) $text));

        return match (true) {
            str_contains($text, 'orange') => 'orange_money',
            str_contains($text, 'moov') => 'moov_money',
            str_contains($text, 'espece') || str_contains($text, 'cash') || str_contains($text, 'livraison') => 'cash',
            default => 'other',
        };
    }
};
