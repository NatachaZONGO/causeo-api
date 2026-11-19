<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\Sales\CounterSaleService;
use App\Services\Sales\OrderStatusService;
use App\Services\Sales\PaymentService;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Commandes de l'assistant et ventes au comptoir. Les lignes (order.items) sont lues depuis
 * order_items ; orders.items (JSON) n'est plus qu'une copie de secours.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Lister les commandes d'une entreprise, les plus récentes d'abord.
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);
        $this->checkOrdersModule($business);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'fulfillment_type' => ['nullable', Rule::in(Order::FULFILLMENT_TYPES)],
            'source' => ['nullable', Rule::in(Order::SOURCES)],
            'payment_status' => ['nullable', Rule::in(Order::PAYMENT_STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
        ], [
            'status.in' => 'Le statut doit être l\'un des suivants : '.implode(', ', Order::STATUSES).'.',
            'fulfillment_type.in' => 'Le mode de remise doit être l\'un des suivants : '.implode(', ', Order::FULFILLMENT_TYPES).'.',
            'source.in' => 'L\'origine doit être l\'une des suivantes : '.implode(', ', Order::SOURCES).'.',
            'payment_status.in' => 'Le paiement doit être l\'un des suivants : '.implode(', ', Order::PAYMENT_STATUSES).'.',
            'q.max' => 'La recherche ne doit pas dépasser 100 caractères.',
        ]);

        $orders = $business->orders()
            ->with('lines.product')
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['fulfillment_type'] ?? null, fn ($query, $type) => $query->where('fulfillment_type', $type))
            ->when($data['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($data['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->when(trim($data['q'] ?? ''), fn ($query, $search) => $this->search($query, $search))
            ->latest()
            ->paginate(20);

        $orders->getCollection()->each(fn (Order $order) => $order->presentLines());

        return response()->json($orders);
    }

    /**
     * Afficher une commande, avec ses versements.
     */
    public function show(Order $order): JsonResponse
    {
        $order->loadMissing('business');

        $this->checkOwnership($order->business);
        $this->checkOrdersModule($order->business);

        return response()->json([
            'order' => $this->present($order),
        ]);
    }

    /**
     * Changer le statut d'une commande (passages autorisés : Order::TRANSITIONS).
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $order->loadMissing('business');

        $this->checkOwnership($order->business);
        $this->checkOrdersModule($order->business, write: true);

        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'reason' => ['nullable', 'string', 'max:100'],
        ], [
            'status.required' => 'Le statut est obligatoire.',
            'status.in' => 'Le statut doit être l\'un des suivants : '.implode(', ', Order::STATUSES).'.',
            'reason.max' => 'Le motif ne doit pas dépasser 100 caractères.',
        ]);

        try {
            $order = $this->statuses->change($order, $data['status'], auth()->id(), $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['status' => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'message' => 'Le statut de la commande a été mis à jour.',
            'order' => $this->present($order),
        ]);
    }

    /**
     * Vente au comptoir : articles du catalogue, prix pratiqué, client facultatif, premier versement.
     */
    public function storeSale(Request $request, Business $business, CounterSaleService $sales): JsonResponse
    {
        $this->checkOwnership($business);
        $this->checkOrdersModule($business, write: true);
        abort_unless($business->hasModule('catalog'), 403, 'La vente au comptoir utilise le catalogue : activez le module Catalogue.');

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'payment_method' => ['required', Rule::in(CounterSaleService::METHODS)],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:30', 'regex:'.PhoneNumber::PATTERN],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'items.required' => 'Ajoutez au moins un article à la vente.',
            'items.min' => 'Ajoutez au moins un article à la vente.',
            'items.*.variant_id.required' => 'Choisissez la variante (taille, couleur…) de chaque article.',
            'items.*.variant_id.uuid' => 'Article inconnu.',
            'items.*.quantity.required' => 'Indiquez la quantité de chaque article.',
            'items.*.quantity.integer' => 'La quantité doit être un nombre entier.',
            'items.*.quantity.min' => 'La quantité doit être d\'au moins 1.',
            'items.*.unit_price.numeric' => 'Le prix doit être un montant en FCFA.',
            'items.*.unit_price.min' => 'Le prix ne peut pas être négatif.',
            'payment_method.required' => 'Choisissez le moyen de paiement.',
            'payment_method.in' => 'Moyen de paiement : espèces (cash), Orange Money, Moov Money ou à crédit (credit).',
            'amount_paid.numeric' => 'Le montant versé doit être un montant en FCFA.',
            'amount_paid.min' => 'Le montant versé ne peut pas être négatif.',
            'customer_name.max' => 'Le nom du client ne doit pas dépasser 100 caractères.',
            'customer_phone.regex' => 'Le téléphone du client n\'est pas valide (chiffres, avec l\'indicatif du pays s\'il n\'est pas d\'ici, par exemple +225…).',
            'notes.max' => 'La note ne doit pas dépasser 500 caractères.',
        ]);

        try {
            $order = $sales->create($business, $data, auth()->id());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['items' => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'message' => 'La vente '.$order->reference().' est enregistrée.',
            'order' => $this->present($order),
        ], 201);
    }

    /**
     * Versement complémentaire sur une commande ou une vente (paiement partiel, crédit).
     */
    public function storePayment(Request $request, Order $order): JsonResponse
    {
        $order->loadMissing('business');

        $this->checkOwnership($order->business);
        $this->checkOrdersModule($order->business, write: true);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(OrderPayment::METHODS)],
            'note' => ['nullable', 'string', 'max:250'],
        ], [
            'amount.required' => 'Indiquez le montant versé.',
            'amount.numeric' => 'Le montant doit être un montant en FCFA.',
            'amount.gt' => 'Le montant doit être supérieur à 0.',
            'method.required' => 'Choisissez le moyen de paiement.',
            'method.in' => 'Moyen de paiement : espèces (cash), Orange Money, Moov Money ou autre.',
            'note.max' => 'La note ne doit pas dépasser 250 caractères.',
        ]);

        try {
            $this->payments->addPayment($order, (float) $data['amount'], $data['method'], $data['note'] ?? null, auth()->id());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['amount' => [$e->getMessage()]]], 422);
        }

        $order->refresh();

        return response()->json([
            'message' => $order->payment_status === Order::PAYMENT_PAID ? 'Versement enregistré : la commande est soldée.' : 'Versement enregistré. Reste à payer : '.PaymentService::money($order->balanceDue()).'.',
            'order' => $this->present($order),
        ], 201);
    }

    /**
     * Commande avec ses lignes (depuis order_items), ses versements et le reste à payer.
     */
    private function present(Order $order): Order
    {
        $order->loadMissing(['lines.product', 'payments']);
        $order->presentLines();
        $order->setAttribute('balance_due', $order->balanceDue());

        return $order;
    }

    /**
     * Rechercher, sans tenir compte de la casse, dans la référence (fin de l'id),
     * le nom du client, le téléphone, la ville et les noms d'articles.
     *
     * @param  Builder<Order>|HasMany<Order>  $query
     */
    private function search(Builder|HasMany $query, string $search): void
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
        $like = DB::getDriverName() === 'pgsql' ? 'LIKE ?' : "LIKE ? ESCAPE '\\'";

        // Transition : une commande sans lignes en table se cherche encore dans sa copie JSON.
        $jsonNames = DB::getDriverName() === 'pgsql'
            ? "EXISTS (SELECT 1 FROM json_array_elements(orders.items) AS item WHERE LOWER(item->>'name') LIKE ?)"
            : "EXISTS (SELECT 1 FROM json_each(orders.items) AS item WHERE LOWER(json_extract(item.value, '$.name')) LIKE ? ESCAPE '\\')";

        $query->where(function ($query) use ($pattern, $like, $jsonNames) {
            $query->whereRaw("LOWER(CAST(orders.id AS TEXT)) {$like}", [$pattern])
                ->orWhereRaw("LOWER(orders.customer_name) {$like}", [$pattern])
                ->orWhereRaw("LOWER(orders.customer_phone) {$like}", [$pattern])
                ->orWhereRaw("LOWER(orders.delivery_city) {$like}", [$pattern])
                ->orWhereHas('lines', fn ($lines) => $lines->whereRaw("LOWER(order_items.name) {$like}", [$pattern]))
                ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('lines')->whereRaw($jsonNames, [$pattern]));
        });
    }

    /**
     * Refuser l'accès aux commandes si le module n'est pas activé pour l'entreprise.
     * Pour une modification ($write), la formule doit aussi comprendre le module :
     * en Gratuit, les commandes existantes restent consultables, en lecture seule.
     */
    private function checkOrdersModule(Business $business, bool $write = false): void
    {
        abort_unless($business->moduleEnabled('orders'), 403, "Le module Commandes n'est pas activé pour cette entreprise.");

        if ($write) {
            abort_unless($business->hasModule('orders'), 403, "Votre formule {$business->billingState()->plan->name} ne permet pas de gérer les commandes : passez en Pro pour les traiter.");
        }
    }

    /**
     * Vérifier que l'entreprise appartient à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
