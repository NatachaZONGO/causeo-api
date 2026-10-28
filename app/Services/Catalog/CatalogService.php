<?php

namespace App\Services\Catalog;

use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Embedding\EmbeddingService;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Module « catalog » : articles et variantes, stock et ses mouvements, alertes de stock
 * faible, recherche de l'assistant (outil search_catalog).
 */
class CatalogService
{
    /** Noms d'options reconnus pour les filtres taille et couleur de search_catalog. */
    public const SIZE_OPTIONS = ['taille', 'size', 'pointure'];

    public const COLOR_OPTIONS = ['couleur', 'color', 'coloris'];

    /** Articles chargés pour une recherche (catalogues de petits commerces). */
    private const SEARCH_POOL = 1000;

    /** Montant en FCFA dans un texte : « 15 000 FCFA », « 15.000 F », « 15000 francs ». */
    public const PRICE_PATTERN = '/(\d{1,3}(?:[ .,\x{00A0}\x{202F}]\d{3})+|\d{3,})\s*(?:F\s?CFA|FCFA|francs?|F)(?![\p{L}])/iu';

    /** Similarité minimale (embedding) pour qu'un article compte comme proche de la recherche. */
    private const SIMILARITY_THRESHOLD = 0.45;

    /** Mots trop courts ou trop vagues pour la recherche par mots. */
    private const STOP_WORDS = ['les', 'des', 'une', 'pour', 'avec', 'vous', 'avez', 'est', 'que', 'qui', 'dans', 'sur', 'pas', 'combien', 'prix'];

    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Le module est utilisable et le catalogue contient au moins un article actif :
     * l'assistant reçoit alors l'outil search_catalog.
     */
    public function isAvailable(Business $business): bool
    {
        return $business->hasModule('catalog') && $business->products()->where('is_active', true)->exists();
    }

    // --- Articles et variantes ---------------------------------------------------------------

    /**
     * Créer ou modifier un article et ses variantes. Les variantes envoyées avec un id sont
     * modifiées, les autres créées ; celles absentes de la liste sont supprimées. Un article
     * sans variante reçoit une variante aux options vides.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $variants  null : variantes inchangées
     */
    public function saveProduct(Business $business, array $data, ?Product $product = null, ?array $variants = null, ?int $userId = null): Product
    {
        $product = DB::transaction(function () use ($business, $data, $product, $variants, $userId) {
            $product ??= new Product(['business_id' => $business->id]);
            $product->fill(array_intersect_key($data, array_flip(['name', 'description', 'category', 'base_price', 'is_active', 'media_ids'])));
            $product->business_id = $business->id;
            $product->save();

            if ($variants !== null) {
                $this->syncVariants($product, $variants, $userId);
            }

            if (! $product->variants()->exists()) {
                $product->variants()->create(['options' => []]);
            }

            return $product;
        });

        $this->embed($product);

        return $product->load('variants');
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants, ?int $userId): void
    {
        $keep = [];

        foreach ($variants as $data) {
            $variant = ! empty($data['id']) ? $product->variants()->whereKey($data['id'])->first() : null;
            $stock = isset($data['stock_quantity']) ? (int) $data['stock_quantity'] : null;

            $attributes = [
                'options' => self::normalizeOptions($data['options'] ?? []),
                'price' => $data['price'] ?? null,
                'low_stock_threshold' => $data['low_stock_threshold'] ?? ($variant?->low_stock_threshold ?? 2),
                'sku' => $data['sku'] ?? null,
            ];

            if ($variant === null) {
                $variant = $product->variants()->create($attributes);
                $this->setStock($variant, $stock, StockMovement::MANUAL, $userId);
            } else {
                $variant->update($attributes);
                // Stock absent de la requête : inchangé.
                if (array_key_exists('stock_quantity', $data) && $stock !== $variant->stock_quantity) {
                    $this->setStock($variant, $stock, StockMovement::MANUAL, $userId);
                }
            }

            $keep[] = $variant->id;
        }

        $product->variants()->whereNotIn('id', $keep)->delete();
    }

    /**
     * Créer les variantes manquantes à partir de listes d'options (tailles × couleurs) ;
     * une combinaison déjà présente est gardée telle quelle.
     *
     * @param  array<string, list<string>>  $optionLists  {"taille": ["S","M"], "couleur": ["rouge","bleu"]}
     * @param  array{price?: ?float, stock_quantity?: ?int, low_stock_threshold?: ?int}  $defaults
     * @return Collection<int, ProductVariant> variantes créées
     */
    public function generateVariants(Product $product, array $optionLists, array $defaults = [], ?int $userId = null): Collection
    {
        $combinations = [[]];
        foreach ($optionLists as $name => $values) {
            $values = array_values(array_unique(array_filter(array_map(fn ($value) => trim((string) $value), $values), fn ($value) => $value !== '')));
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = $combination + [mb_strtolower(trim((string) $name)) => $value];
                }
            }
            $combinations = $values === [] ? $combinations : $next;
        }

        return DB::transaction(function () use ($product, $combinations, $defaults, $userId) {
            $existing = $product->variants()->get();
            // La variante « sans options » d'un article simple laisse place aux variantes générées.
            $existing->filter(fn (ProductVariant $variant) => $variant->options === [] && $variant->stock_quantity === null)
                ->each(fn (ProductVariant $variant) => $variant->delete());

            $created = collect();
            foreach ($combinations as $options) {
                $options = self::normalizeOptions($options);
                if ($options === [] || $existing->contains(fn (ProductVariant $variant) => self::sameOptions($variant->options ?? [], $options))) {
                    continue;
                }

                $variant = $product->variants()->create([
                    'options' => $options,
                    'price' => $defaults['price'] ?? null,
                    'low_stock_threshold' => $defaults['low_stock_threshold'] ?? 2,
                ]);
                $this->setStock($variant, isset($defaults['stock_quantity']) ? (int) $defaults['stock_quantity'] : null, StockMovement::MANUAL, $userId);
                $created->push($variant->fresh());
            }

            $this->embed($product);

            return $created;
        });
    }

    // --- Stock -------------------------------------------------------------------------------

    /**
     * Fixer le stock d'une variante (null : stock non suivi). La différence est enregistrée
     * comme un mouvement ($reason : manual ou import).
     */
    public function setStock(ProductVariant $variant, ?int $quantity, string $reason = StockMovement::MANUAL, ?int $userId = null): ProductVariant
    {
        return DB::transaction(function () use ($variant, $quantity, $reason, $userId) {
            $locked = ProductVariant::query()->whereKey($variant->id)->lockForUpdate()->firstOrFail();
            $before = $locked->stock_quantity;

            if ($quantity === null || $quantity === $before) {
                $locked->update(['stock_quantity' => $quantity]);

                return $locked;
            }

            $locked->update(['stock_quantity' => $quantity]);
            StockMovement::create([
                'variant_id' => $locked->id,
                'quantity' => $quantity - ($before ?? 0),
                'reason' => $reason,
                'user_id' => $userId,
            ]);

            $this->alertIfThresholdCrossed($locked, $before);

            return $locked;
        });
    }

    /**
     * Retirer du stock les articles d'une commande (lignes avec variant_id), dans la même
     * transaction que sa création. Refus si une variante suivie n'a plus assez de pièces.
     *
     * @throws InvalidArgumentException message destiné à l'IA
     */
    public function reserveForOrder(Order $order, bool $allowNegative = false, ?int $userId = null): void
    {
        foreach ($this->orderLines($order) as $variantId => $quantity) {
            $variant = ProductVariant::query()->with('product')->whereKey($variantId)->lockForUpdate()->first();

            if ($variant === null || $variant->stock_quantity === null) {
                continue;
            }

            if (! $allowNegative && $variant->stock_quantity < $quantity) {
                $label = trim($variant->product->name.' '.$variant->optionsLabel());

                throw new InvalidArgumentException($variant->stock_quantity <= 0
                    ? "« {$label} » est en rupture de stock : ne l'enregistre pas, propose une autre variante ou un article proche (search_catalog)."
                    : "Pas assez de pièces de « {$label} » pour cette quantité : propose une quantité plus faible ou une autre variante, sans donner de nombre exact.");
            }

            $this->move($variant, -$quantity, StockMovement::ORDER, $order, $userId);
        }
    }

    /**
     * Remettre en stock les articles d'une commande annulée (une seule fois).
     */
    public function releaseOrder(Order $order, ?int $userId = null): void
    {
        DB::transaction(function () use ($order, $userId) {
            foreach ($this->reservedByVariant($order) as $variantId => $reserved) {
                $variant = ProductVariant::query()->whereKey($variantId)->lockForUpdate()->first();

                if ($variant !== null && $reserved > 0 && $variant->stock_quantity !== null) {
                    $this->move($variant, $reserved, StockMovement::ORDER_CANCELLED, $order, $userId);
                }
            }
        });
    }

    /**
     * Commande réactivée après une annulation : ses articles sortent à nouveau du stock
     * (décision du gérant, même si le stock devient négatif).
     */
    public function reserveAgain(Order $order, ?int $userId = null): void
    {
        DB::transaction(function () use ($order, $userId) {
            if (array_sum($this->reservedByVariant($order)) === 0) {
                $this->reserveForOrder($order, allowNegative: true, userId: $userId);
            }
        });
    }

    private function move(ProductVariant $variant, int $quantity, string $reason, ?Order $order = null, ?int $userId = null): void
    {
        $before = $variant->stock_quantity;
        $variant->update(['stock_quantity' => $before + $quantity]);

        StockMovement::create([
            'variant_id' => $variant->id,
            'quantity' => $quantity,
            'reason' => $reason,
            'order_id' => $order?->id,
            'user_id' => $userId,
        ]);

        $this->alertIfThresholdCrossed($variant, $before);
    }

    /**
     * Quantités commandées par variante (lignes avec variant_id).
     *
     * @return array<string, int>
     */
    private function orderLines(Order $order): array
    {
        $lines = [];
        foreach ((array) $order->items as $item) {
            if (! empty($item['variant_id'])) {
                $lines[$item['variant_id']] = ($lines[$item['variant_id']] ?? 0) + (int) $item['quantity'];
            }
        }

        return $lines;
    }

    /**
     * Pièces encore retirées du stock par la commande, par variante (sorties moins retours).
     *
     * @return array<string, int>
     */
    private function reservedByVariant(Order $order): array
    {
        return StockMovement::query()
            ->where('order_id', $order->id)
            ->whereIn('reason', [StockMovement::ORDER, StockMovement::ORDER_CANCELLED])
            ->get(['variant_id', 'quantity'])
            ->groupBy('variant_id')
            ->map(fn (Collection $movements) => -$movements->sum('quantity'))
            ->all();
    }

    /**
     * Cloche (et e-mail si le gérant l'a demandé) quand la variante passe sous son seuil
     * ou en rupture ; pas à chaque mouvement sous le seuil.
     */
    private function alertIfThresholdCrossed(ProductVariant $variant, ?int $before): void
    {
        $after = ProductVariant::availabilityFor($variant->stock_quantity, $variant->low_stock_threshold);
        $previous = $before === null ? ProductVariant::AVAILABLE : ProductVariant::availabilityFor($before, $variant->low_stock_threshold);

        $worse = [ProductVariant::AVAILABLE => 0, ProductVariant::LOW_STOCK => 1, ProductVariant::OUT_OF_STOCK => 2];

        if ($worse[$after] > $worse[$previous]) {
            $this->notifications->notifyStock($variant, $after);
        }
    }

    // --- Recherche de l'assistant --------------------------------------------------------------

    /**
     * Articles actifs qui correspondent à la recherche, avec leurs variantes (filtrées par
     * taille, couleur et prix maximal), leur prix et leur disponibilité, jamais le stock exact.
     *
     * @param  array{size?: ?string, color?: ?string, category?: ?string, max_price?: int|float|string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function search(Business $business, string $query, array $filters = [], int $limit = 5): array
    {
        $products = $business->products()->where('is_active', true)->with('variants')->limit(self::SEARCH_POOL)->get();

        if ($products->isEmpty()) {
            return [];
        }

        $tokens = $this->tokens($query);
        $similarities = $tokens === [] ? [] : $this->similarities($business, $query);
        $category = self::normalize((string) ($filters['category'] ?? ''));
        $maxPrice = is_numeric($filters['max_price'] ?? null) ? (float) $filters['max_price'] : null;

        $results = [];

        foreach ($products as $product) {
            if ($category !== '' && ! str_contains(self::normalize((string) $product->category), $category)) {
                continue;
            }

            $variants = $product->variants->filter(fn (ProductVariant $variant) => $this->matchesOption($variant, self::SIZE_OPTIONS, $filters['size'] ?? null)
                && $this->matchesOption($variant, self::COLOR_OPTIONS, $filters['color'] ?? null)
                && ($maxPrice === null || ($variant->price ?? $product->base_price) <= $maxPrice));

            if ($variants->isEmpty()) {
                continue;
            }

            $score = $this->textScore($product, $tokens) + (($similarities[$product->id] ?? 0) >= self::SIMILARITY_THRESHOLD ? 5 * $similarities[$product->id] : 0);

            if ($tokens !== [] && $score <= 0) {
                continue;
            }

            $results[] = ['score' => $score, 'product' => $product, 'variants' => $variants];
        }

        usort($results, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_map(fn (array $result) => $this->present($result['product'], $result['variants']), array_slice($results, 0, $limit));
    }

    /**
     * Le texte nomme-t-il un article du catalogue ? Un texte sans mot utile (« C'est combien ? »)
     * n'en nomme aucun : search() renverrait alors tout le catalogue.
     */
    public function identifies(Business $business, string $text): bool
    {
        return $this->tokens($text) !== [] && $this->search($business, $text, [], 1) !== [];
    }

    /** Article et toutes ses variantes, tels que l'assistant les voit (statut du jour…). */
    public function describe(Product $product): array
    {
        return $this->present($product, $product->variants);
    }

    /**
     * Prix cités dans une réponse pour un article du catalogue qui ne correspondent à aucune de ses
     * variantes (ni à 2 à 10 pièces). Seules les phrases qui nomment un article sont vérifiées, hors
     * total, livraison, frais, remise ou acompte.
     *
     * @return list<array{product: string, price: int, prices: list<int>}>
     */
    public function priceMismatches(Business $business, string $text): array
    {
        if (preg_match(self::PRICE_PATTERN, $text) !== 1) {
            return [];
        }

        $products = $business->products()->where('is_active', true)->with('variants')->limit(self::SEARCH_POOL)->get();
        $mismatches = [];

        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [] as $sentence) {
            preg_match_all(self::PRICE_PATTERN, $sentence, $matches);
            $normalized = self::normalize($sentence);

            if ($matches[1] === [] || preg_match('/\b(total|livraison|frais|remise|reduction|acompte|avance|reste)\b/', $normalized) === 1) {
                continue;
            }

            $words = $this->tokens($sentence);
            $named = $products->filter(function (Product $product) use ($words): bool {
                $tokens = $this->tokens($product->name);
                $found = count(array_intersect($tokens, $words));

                return $tokens !== [] && ($found === count($tokens) || ($found >= 2 && $found / count($tokens) >= 0.6));
            });

            if ($named->isEmpty()) {
                continue;
            }

            $prices = $named->flatMap(fn (Product $product) => $product->variants->map(fn (ProductVariant $variant) => (int) round($variant->price ?? $product->base_price))
                ->push((int) round((float) $product->base_price)))
                ->filter()->unique()->sort()->values()->all();
            $allowed = collect($prices)->flatMap(fn (int $price) => array_map(fn (int $count) => $price * $count, range(1, 10)))->all();

            foreach ($matches[1] as $raw) {
                $price = (int) preg_replace('/\D/', '', $raw);

                if ($price > 0 && ! in_array($price, $allowed, true)) {
                    $mismatches[] = ['product' => $named->pluck('name')->implode(', '), 'price' => $price, 'prices' => $prices];
                }
            }
        }

        return $mismatches;
    }

    /**
     * Article tel que l'assistant le voit : prix et disponibilité par variante.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return array<string, mixed>
     */
    private function present(Product $product, Collection $variants): array
    {
        $variants = $variants->take(30)->map(fn (ProductVariant $variant) => array_filter([
            'variant_id' => $variant->id,
            'options' => $variant->options ?: null,
            'price' => $variant->price ?? $product->base_price,
            'availability' => ProductVariant::AVAILABILITY_LABELS[$variant->availability],
        ], fn ($value) => $value !== null))->values()->all();

        return array_filter([
            'product_id' => $product->id,
            'name' => $product->name,
            'category' => $product->category,
            'description' => $product->description !== null ? Str::limit($product->description, 300) : null,
            'currency' => 'FCFA',
            'variants' => $variants,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  list<string>  $names
     */
    private function matchesOption(ProductVariant $variant, array $names, mixed $wanted): bool
    {
        $wanted = self::normalize((string) $wanted);

        if ($wanted === '') {
            return true;
        }

        foreach ((array) $variant->options as $name => $value) {
            if (in_array(self::normalize((string) $name), $names, true)) {
                return self::normalize((string) $value) === $wanted;
            }
        }

        // L'article n'a pas cette option (taille unique…) : il reste proposé.
        return true;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function textScore(Product $product, array $tokens): float
    {
        $fields = [
            [self::normalize($product->name), 3],
            [self::normalize((string) $product->category), 2],
            [self::normalize($product->variants->map(fn (ProductVariant $variant) => implode(' ', (array) $variant->options))->implode(' ')), 1.5],
            [self::normalize((string) $product->description), 1],
        ];

        $score = 0.0;
        foreach ($tokens as $token) {
            foreach ($fields as [$text, $weight]) {
                if ($text !== '' && preg_match('/\b'.preg_quote($token, '/').'/u', $text) === 1) {
                    $score += $weight;
                }
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $words = preg_split('/[^a-z0-9]+/', self::normalize($query)) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) >= 3 && ! in_array($word, self::STOP_WORDS, true))));
    }

    /**
     * Similarité de chaque article avec la recherche (PostgreSQL et pgvector seulement).
     *
     * @return array<string, float>
     */
    private function similarities(Business $business, string $query): array
    {
        if (! $this->usesEmbeddings()) {
            return [];
        }

        try {
            $vector = '['.implode(',', $this->embeddings->embed($query)).']';

            return collect(DB::select(
                'SELECT id, 1 - (embedding <=> ?::vector) AS similarity FROM products
                 WHERE business_id = ? AND is_active = true AND embedding IS NOT NULL
                 ORDER BY embedding <=> ?::vector LIMIT 20',
                [$vector, $business->id, $vector],
            ))->mapWithKeys(fn (object $row) => [$row->id => (float) $row->similarity])->all();
        } catch (Throwable $e) {
            Log::warning('CatalogService: recherche par similarité ignorée.', ['business_id' => $business->id, 'exception' => $e::class]);

            return [];
        }
    }

    /**
     * Embedding de l'article (nom, catégorie, description, options), pour search_catalog.
     */
    public function embed(Product $product): void
    {
        if (! $this->usesEmbeddings()) {
            return;
        }

        $options = $product->variants()->get()->flatMap(fn (ProductVariant $variant) => array_values((array) $variant->options))->unique()->implode(', ');
        $text = trim(implode('. ', array_filter([$product->name, $product->category, $product->description, $options])));

        try {
            DB::statement('UPDATE products SET embedding = ?::vector WHERE id = ?', [
                '['.implode(',', $this->embeddings->embed($text)).']',
                $product->id,
            ]);
        } catch (Throwable $e) {
            Log::warning('CatalogService: embedding de l\'article impossible.', ['product_id' => $product->id, 'exception' => $e::class]);
        }
    }

    /** Embeddings sur PostgreSQL (pgvector) avec une clé Gemini ; sinon la recherche se fait par mots. */
    private function usesEmbeddings(): bool
    {
        return DB::getDriverName() === 'pgsql' && ! empty(config('services.gemini.api_key'));
    }

    // --- Outils --------------------------------------------------------------------------------

    /**
     * Options normalisées : noms en minuscules, valeurs sans espaces autour, vides retirées.
     *
     * @param  array<mixed>  $options
     * @return array<string, string>
     */
    public static function normalizeOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $name => $value) {
            $name = mb_strtolower(trim((string) $name));
            $value = trim((string) $value);
            if ($name !== '' && $value !== '') {
                $normalized[$name] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $a
     * @param  array<string, string>  $b
     */
    public static function sameOptions(array $a, array $b): bool
    {
        $key = fn (array $options) => collect(self::normalizeOptions($options))
            ->mapWithKeys(fn ($value, $name) => [$name => self::normalize($value)])->sortKeys()->all();

        return $key($a) === $key($b);
    }

    /** Minuscules, sans accents ni espaces superflus. */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }
}
