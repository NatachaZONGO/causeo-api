<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Order;
use InvalidArgumentException;

class OrderService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * Créer une commande à partir des données de l'outil create_order.
     * Le total est toujours recalculé ici à partir des articles.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException si les données sont incomplètes (message destiné à l'IA)
     */
    public function createFromAi(Business $business, Conversation $conversation, array $input): Order
    {
        $items = $this->normalizeItems($input['items'] ?? null);

        $customerName = $this->string($input['customer_name'] ?? null) ?? $conversation->customer_name;
        $deliveryCity = $this->string($input['delivery_city'] ?? null);
        $deliveryAddress = $this->string($input['delivery_address'] ?? null);
        $paymentMethod = $this->string($input['payment_method'] ?? null);

        $missing = array_keys(array_filter([
            'customer_name' => $customerName === null,
            'delivery_city' => $deliveryCity === null,
            'delivery_address' => $deliveryAddress === null,
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

        $order = Order::create([
            'business_id' => $business->id,
            'conversation_id' => $conversation->id,
            'customer_phone' => $conversation->customer_phone,
            'customer_name' => $customerName,
            'items' => $items,
            'total_amount' => $this->total($items),
            'delivery_city' => $deliveryCity,
            'delivery_address' => $deliveryAddress,
            'payment_method' => $paymentMethod,
            'status' => 'new',
            'notes' => $this->string($input['notes'] ?? null),
        ]);

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
     * @return array<int, array{name: string, options: ?string, quantity: int, unit_price: float}>
     */
    private function normalizeItems(mixed $items): array
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

            $normalized[] = [
                'name' => $name,
                'options' => $this->string($options),
                'quantity' => (int) $quantity,
                'unit_price' => (float) $unitPrice,
            ];
        }

        return $normalized;
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
