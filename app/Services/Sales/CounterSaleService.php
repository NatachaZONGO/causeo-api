<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogService;
use App\Services\OrderService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Vente au comptoir saisie par le gérant : articles du catalogue (variante, quantité, prix
 * pratiqué éventuellement négocié), client facultatif, premier versement. La vente sort du stock
 * tout de suite, dans la même transaction et avec le même verrou que les commandes de
 * l'assistant ; elle est remise au client (statut « delivered »).
 *
 * Paiement : « credit » (rien de versé) ou un premier versement en espèces, Orange Money ou Moov
 * Money, du total (payée) ou d'une partie (partielle). Une vente non soldée exige le nom et le
 * téléphone du client (suivi des crédits, phase 2).
 */
class CounterSaleService
{
    /** Moyens proposés à la vente ; « credit » : vente à crédit, sans versement. */
    public const CREDIT = 'credit';

    public const METHODS = [OrderPayment::CASH, OrderPayment::ORANGE_MONEY, OrderPayment::MOOV_MONEY, self::CREDIT];

    public const METHOD_LABELS = OrderPayment::METHOD_LABELS + [self::CREDIT => 'À crédit'];

    public function __construct(
        private readonly CatalogService $catalog,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array{items: list<array{variant_id: string, quantity: int, unit_price?: float|int|string|null}>, payment_method: string, amount_paid?: float|int|string|null, customer_name?: ?string, customer_phone?: ?string, notes?: ?string}  $data
     *
     * @throws InvalidArgumentException message destiné au gérant
     */
    public function create(Business $business, array $data, ?int $userId = null): Order
    {
        $lines = $this->lines($business, $data['items'] ?? []);
        $total = round(array_sum(array_map(fn (array $line) => $line['quantity'] * $line['unit_price'], $lines)), 2);

        $method = (string) ($data['payment_method'] ?? '');
        if (! in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Choisissez le moyen de paiement : espèces, Orange Money, Moov Money ou à crédit.');
        }

        $paid = $method === self::CREDIT ? 0.0 : round((float) ($data['amount_paid'] ?? $total), 2);
        if ($paid < 0) {
            throw new InvalidArgumentException('Le montant versé ne peut pas être négatif.');
        }
        if ($paid > $total) {
            throw new InvalidArgumentException('Le montant versé ('.PaymentService::money($paid).') dépasse le total ('.PaymentService::money($total).'). Pour un paiement en espèces, indiquez le total et rendez la monnaie.');
        }

        $name = $this->string($data['customer_name'] ?? null);
        $rawPhone = $this->string($data['customer_phone'] ?? null);
        $phone = $rawPhone !== null ? PhoneNumber::normalize($rawPhone, $business->country) : null;
        if ($rawPhone !== null && $phone === null) {
            throw new InvalidArgumentException("Le numéro « {$rawPhone} » n'est pas valide : indiquez-le avec l'indicatif du pays si le client n'est pas d'ici (par exemple +225 07 00 00 00 00).");
        }
        if ($paid < $total && ($name === null || $phone === null)) {
            throw new InvalidArgumentException('Vente à crédit ou paiement partiel : le nom et le téléphone du client sont obligatoires pour suivre ce qu\'il reste à payer.');
        }

        return DB::transaction(function () use ($business, $lines, $total, $method, $paid, $name, $phone, $data, $userId) {
            $now = now();
            $order = Order::create([
                'business_id' => $business->id,
                'customer_name' => $name,
                'customer_phone' => $phone,
                // Copie de secours (transition), au format des commandes de l'assistant.
                'items' => array_map(fn (array $line) => [
                    'name' => $line['name'],
                    'options' => $line['options'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'variant_id' => $line['variant_id'],
                ], $lines),
                'total_amount' => $total,
                'payment_method' => self::METHOD_LABELS[$method],
                'fulfillment_type' => 'pickup',
                'status' => 'delivered',
                'confirmed_at' => $now,
                'delivered_at' => $now,
                'notes' => $this->string($data['notes'] ?? null),
                'source' => Order::SOURCE_COUNTER,
                'created_by' => $userId,
            ]);

            $this->orders->writeLines($order, $lines);
            $this->catalog->reserveForOrder($order, userId: $userId, forOwner: true);

            if ($paid > 0) {
                $this->payments->addPayment($order, $paid, $method, userId: $userId, paidAt: $now);
            } else {
                $this->payments->refresh($order);
            }

            return $order->fresh(['lines.product', 'payments']);
        });
    }

    /**
     * Lignes de la vente, avec le prix du catalogue et le prix pratiqué (négocié ou celui du catalogue).
     *
     * @param  mixed  $items
     * @return list<array{name: string, options: ?string, quantity: int, unit_price: float, catalog_unit_price: float, variant_id: string}>
     *
     * @throws InvalidArgumentException
     */
    private function lines(Business $business, mixed $items): array
    {
        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('Ajoutez au moins un article à la vente.');
        }

        $lines = [];
        foreach (array_values($items) as $index => $item) {
            $position = $index + 1;
            $variant = ProductVariant::query()
                ->with('product')
                ->whereKey((string) ($item['variant_id'] ?? ''))
                ->whereHas('product', fn ($query) => $query->where('business_id', $business->id))
                ->first();

            if ($variant === null) {
                throw new InvalidArgumentException("Article {$position} : introuvable dans votre catalogue (il a peut-être été supprimé).");
            }

            $label = trim($variant->product->name.' '.$variant->optionsLabel());
            if (! $variant->product->is_active) {
                throw new InvalidArgumentException("« {$label} » n'est plus en vente : réactivez-le dans le catalogue pour le vendre.");
            }

            $quantity = $item['quantity'] ?? null;
            if (! is_numeric($quantity) || (int) $quantity != $quantity || (int) $quantity < 1) {
                throw new InvalidArgumentException("« {$label} » : la quantité doit être un nombre entier d'au moins 1.");
            }

            $catalogPrice = round((float) $variant->effective_price, 2);
            $price = $item['unit_price'] ?? null;
            if ($price !== null && $price !== '' && (! is_numeric($price) || (float) $price < 0)) {
                throw new InvalidArgumentException("« {$label} » : le prix doit être un montant positif.");
            }

            $lines[] = [
                'name' => $variant->product->name,
                'options' => $this->string($variant->optionsLabel()),
                'quantity' => (int) $quantity,
                'unit_price' => $price === null || $price === '' ? $catalogPrice : round((float) $price, 2),
                'catalog_unit_price' => $catalogPrice,
                'variant_id' => $variant->id,
            ];
        }

        return $lines;
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
