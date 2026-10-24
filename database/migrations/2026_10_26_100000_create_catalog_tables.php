<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module « catalog » (Catalogue et stock) : articles, variantes (taille, couleur…)
     * avec leur stock, et l'historique des mouvements de stock. Pas de fournisseurs,
     * d'achats ni de comptabilité dans cette première version.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->decimal('base_price', 12, 2); // FCFA
            $table->boolean('is_active')->default(true);
            // Photos de l'article, parmi les médias du business (business_media).
            $table->json('media_ids')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'category']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            // {"taille": "M", "couleur": "rouge"} ; {} pour un article sans variantes.
            $table->json('options');
            // Null : prix de l'article (products.base_price).
            $table->decimal('price', 12, 2)->nullable();
            // Null : stock non suivi (toujours disponible).
            $table->integer('stock_quantity')->nullable();
            $table->unsignedInteger('low_stock_threshold')->default(2);
            $table->string('sku')->nullable();
            $table->timestamps();

            $table->index('product_id');
            $table->index('sku');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->integer('quantity'); // + entrée, - sortie
            $table->enum('reason', ['order', 'order_cancelled', 'manual', 'import']);
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['variant_id', 'created_at']);
        });

        // Alerte de stock faible ou de rupture : la cloche toujours, l'e-mail si le gérant le veut.
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('stock_alert_email')->default(false);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Recherche de l'outil search_catalog (nom, description, catégorie, options) ;
            // même dimension que les autres embeddings (EmbeddingService).
            DB::statement('ALTER TABLE products ADD COLUMN embedding vector(1536)');
            DB::statement('CREATE INDEX products_embedding_idx ON products USING hnsw (embedding vector_cosine_ops)');

            // RLS sans politique : aucun accès via l'API Supabase (anon / authenticated).
            foreach (['products', 'product_variants', 'stock_movements'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            }
        }

        $this->setNotificationTypes(['escalation', 'order', 'appointment', 'whatsapp', 'billing', 'feedback', 'stock']);

        // Inclus par défaut pour le modèle Boutique (les businesses existants le choisissent eux-mêmes).
        $template = Schema::hasColumn('business_templates', 'default_modules')
            ? DB::table('business_templates')->where('slug', 'boutique')->first(['id', 'default_modules'])
            : null;
        if ($template !== null) {
            $modules = json_decode((string) $template->default_modules, true) ?: [];
            DB::table('business_templates')->where('id', $template->id)
                ->update(['default_modules' => json_encode(array_values(array_unique([...$modules, 'catalog'])))]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->setNotificationTypes(['escalation', 'order', 'appointment', 'whatsapp', 'billing', 'feedback']);

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('stock_alert_email');
        });

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }

    /**
     * @param  list<string>  $types
     */
    private function setNotificationTypes(array $types): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(', ', array_map(fn (string $type) => "'{$type}'", $types));

            DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ({$values}))");

            return;
        }

        Schema::table('notifications', function (Blueprint $table) use ($types) {
            $table->enum('type', $types)->change();
        });
    }
};
