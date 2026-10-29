<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OrderService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly CatalogService $catalog,
    ) {
    }

    /**
     * Créer une commande à partir des données de l'outil create_order.
     * Le total est toujours recalculé ici à partir des articles. Une ligne avec variant_id
     * (module Catalogue) prend le nom, les options et le prix du catalogue, est refusée en
     * rupture, et sort du stock dans la même transaction que la création de la commande. Ses
     * suppléments (restaurant : « fromage +500 FCFA ») s'ajoutent au prix unitaire.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException si les données sont incomplètes (message destiné à l'IA)
     */
    public function createFromAi(Business $business, Conversation $conversation, array $input): Order
    {
        $items = $this->normalizeItems($input['items'] ?? null, $business->hasModule('catalog') ? $business : null);

        $fulfillmentType = $this->string($input['fulfillment_type'] ?? null);
        $enabled = $business->enabledFulfillmentTypes();

        if ($fulfillmentType === null) {
            throw new InvalidArgumentException('Mode de remise manquant (fulfillment_type). Demande au client comment il souhaite recevoir sa commande.');
        }
        if (! in_array($fulfillmentType, Order::FULFILLMENT_TYPES, true)) {
            throw new InvalidArgumentException("Mode de remise inconnu : {$fulfillmentType}.");
        }
        if (! in_array($fulfillmentType, $enabled, true)) {
            throw new InvalidArgumentException(
                "Le mode de remise « {$fulfillmentType} » n'est pas proposé par la boutique. Modes disponibles : "
                .($enabled === [] ? 'aucun' : implode(', ', $enabled)).'.'
            );
        }

        // Retrait ou sur place (restaurant) : ni adresse ni frais de livraison.
        $isPickup = in_array($fulfillmentType, ['pickup', 'dine_in'], true);

        if ($isPickup) {
            foreach ($items as $item) {
                if ($this->isDeliveryFee($item['name'])) {
                    $mode = $fulfillmentType === 'dine_in' ? 'une commande sur place' : 'un retrait en boutique';
                    throw new InvalidArgumentException("Aucun frais de livraison ne doit être facturé pour {$mode} : retire la ligne « {$item['name']} ».");
                }
            }
        }

        $customerName = $this->string($input['customer_name'] ?? null) ?? $conversation->customer_name;
        $deliveryCity = $isPickup ? null : $this->string($input['delivery_city'] ?? null);
        $deliveryAddress = $isPickup ? null : $this->string($input['delivery_address'] ?? null);
        $paymentMethod = $this->string($input['payment_method'] ?? null);

        $missing = array_keys(array_filter([
            'customer_name' => $customerName === null,
            'delivery_city' => ! $isPickup && $deliveryCity === null,
            'delivery_address' => $fulfillmentType === 'delivery' && $deliveryAddress === null,
            'payment_method' => $paymentMethod === null,
        ]));

        if ($missing !== []) {
            throw new InvalidArgumentException('Informations manquantes : '.implode(', ', $missing).'. Demande-les au client avant de réessayer.');
        }

        // Le client a pu confirmer deux fois : on ne duplique pas une commande identique récente.
        $duplicate = Order::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', 'new')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest()
            ->get()
            ->first(fn (Order $order) => $order->items == $items);

        if ($duplicate !== null) {
            return $duplicate;
        }

        $order = DB::transaction(fn () => tap(Order::create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_phone' => $conversation->customer_phone,
            'customer_name' => $customerName,
            'items' => $items,
            'total_amount' => $this->total($items),
            'delivery_city' => $deliveryCity,
            'delivery_address' => $deliveryAddress,
            'payment_method' => $paymentMethod,
            'fulfillment_type' => $fulfillmentType,
            'pickup_time' => $isPickup ? $this->string($input['pickup_time'] ?? null) : null,
            'status' => 'new',
            'notes' => $this->string($input['notes'] ?? null),
        ]), fn (Order $order) => $this->catalog->reserveForOrder($order)));

        $this->notificationService->notifyOrder($order);

        return $order;
    }

    /**
     * @param  array<int, array{quantity: int, unit_price: float}>  $items
     */
    public function total(array $items): float
    {
        return round(array_sum(array_map(
            fn (array $item) => $item['quantity'] * $item['unit_price'],
            $items,
        )), 2);
    }

    /**
     * @param  ?Business  $catalogBusiness  business dont le catalogue fait foi (module actif), sinon null
     * @return array<int, array{name: string, options: ?string, quantity: int, unit_price: float, variant_id?: string, extras?: list<array{name: string, price: float}>}>
     */
    private function normalizeItems(mixed $items, ?Business $catalogBusiness = null): array
    {
        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('La commande doit contenir au moins un article.');
        }

        $normalized = [];

        foreach (array_values($items) as $index => $item) {
            $position = $index + 1;
            $name = is_array($item) ? $this->string($item['name'] ?? null) : null;
            $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;
            $unitPrice = is_array($item) ? ($item['unit_price'] ?? null) : null;

            $variant = $catalogBusiness !== null && is_array($item) && ! empty($item['variant_id'])
                ? $this->catalogVariant($catalogBusiness, (string) $item['variant_id'], $position)
                : null;

            $extras = [];
            if ($variant !== null) {
                // Le catalogue fait foi : nom, options et prix de la variante, plus ses suppléments.
                $name = $variant->product->name;
                $extras = $this->catalogExtras($variant, $item['extras'] ?? [], $position);
                $unitPrice = $variant->effective_price + array_sum(array_column($extras, 'price'));
            }

            if ($name === null) {
                throw new InvalidArgumentException("Article {$position} : le nom est manquant.");
            }
            if (! is_numeric($quantity) || (int) $quantity != $quantity || (int) $quantity < 1) {
                throw new InvalidArgumentException("Article {$position} ({$name}) : la quantité doit être un entier supérieur ou égal à 1.");
            }
            if (! is_numeric($unitPrice) || (float) $unitPrice < 0) {
                throw new InvalidArgumentException("Article {$position} ({$name}) : le prix unitaire est manquant ou invalide. N'invente jamais un prix absent du contexte.");
            }

            $options = $item['options'] ?? null;
            if (is_array($options)) {
                $options = implode(', ', array_filter(array_map(fn ($value) => $this->string($value), $options)));
            }

            $label = $variant !== null ? ($this->string($variant->optionsLabel()) ?? $this->string($options)) : $this->string($options);
            if ($extras !== []) {
                $label = $this->string(implode(', ', array_filter([
                    $label,
                    ...array_map(fn (array $extra) => 'supplément '.mb_strtolower($extra['name']).' (+'.number_format($extra['price'], 0, ',', ' ').' FCFA)', $extras),
                ])));
            }

            $normalized[] = [
                'name' => $name,
                'options' => $label,
                'quantity' => (int) $quantity,
                'unit_price' => (float) $unitPrice,
            ] + ($variant !== null ? ['variant_id' => $variant->id] : []) + ($extras !== [] ? ['extras' => $extras] : []);
        }

        return $normalized;
    }

    /**
     * Variante du catalogue du business, en vente et pas en rupture.
     *
     * @throws InvalidArgumentException message destiné à l'IA
     */
    private function catalogVariant(Business $business, string $variantId, int $position): ProductVariant
    {
        $variant = ProductVariant::query()
            ->with('product')
            ->whereKey($variantId)
            ->whereHas('product', fn ($query) => $query->where('business_id', $business->id))
            ->first();

        if ($variant === null) {
            throw new InvalidArgumentException("Article {$position} : variant_id inconnu. Utilise uniquement un variant_id renvoyé par search_catalog.");
        }

        $label = trim($variant->product->name.' '.$variant->optionsLabel());

        if (! $variant->product->is_active) {
            throw new InvalidArgumentException("Article {$position} : « {$label} » n'est plus en vente. Propose un autre article (search_catalog).");
        }

        if ($variant->availability === ProductVariant::OUT_OF_STOCK) {
            throw new InvalidArgumentException("Article {$position} : « {$label} » est en rupture de stock. Ne l'enregistre pas : propose une autre variante ou un article proche (search_catalog).");
        }

        return $variant;
    }

    /**
     * Suppléments choisis pour un plat du catalogue, avec le prix du catalogue.
     *
     * @return list<array{name: string, price: float}>
     *
     * @throws InvalidArgumentException message destiné à l'IA
     */
    private function catalogExtras(ProductVariant $variant, mixed $requested, int $position): array
    {
        $offered = collect((array) $variant->product->extras)->keyBy(fn (array $extra) => CatalogService::normalize($extra['name']));
        $chosen = [];

        foreach ((array) $requested as $extra) {
            $name = is_array($extra) ? $this->string($extra['name'] ?? null) : $this->string($extra);
            if ($name === null) {
                continue;
            }

            $match = $offered->get(CatalogService::normalize($name))
                ?? $offered->first(fn (array $offer, string $key) => str_contains($key, CatalogService::normalize($name)) || str_contains(CatalogService::normalize($name), $key));

            if ($match === null) {
                $list = $offered->isEmpty() ? 'aucun' : $offered->map(fn (array $offer) => $offer['name'].' (+'.number_format((float) $offer['price'], 0, ',', ' ').' FCFA)')->implode(', ');
                throw new InvalidArgumentException("Article {$position} : le supplément « {$name} » n'existe pas pour « {$variant->product->name} ». Suppléments proposés : {$list}.");
            }

            $chosen[CatalogService::normalize($match['name'])] = ['name' => $match['name'], 'price' => (float) $match['price']];
        }

        return array_values($chosen);
    }

    /**
     * Reconnaître une ligne de frais de livraison / d'expédition.
     */
    private function isDeliveryFee(string $name): bool
    {
        $normalized = Str::lower(Str::ascii($name));

        return str_contains($normalized, 'livraison')
            || (str_contains($normalized, 'frais') && preg_match('/\b(port|expedition|envoi|transport)\b/', $normalized) === 1);
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
