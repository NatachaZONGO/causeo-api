<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Customers\CustomerListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Clients du gérant propriétaire (pas d'accès admin), dans toutes les formules, comme l'export des
 * contacts. Un versement s'ajoute depuis la fiche par POST /orders/{order}/payments, qui garde ses
 * propres règles (module Commandes, formule).
 */
class CustomerController extends Controller
{
    /** Commandes renvoyées dans la fiche, les plus récentes d'abord. */
    private const ORDERS_LIMIT = 100;

    /**
     * Liste paginée : recherche (nom, numéro), tri, filtre « Doit de l'argent ».
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(CustomerListQuery::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'owes' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'search.max' => 'La recherche ne doit pas dépasser 100 caractères.',
            'sort.in' => 'Tri possible : '.implode(', ', CustomerListQuery::SORTS).'.',
            'direction.in' => 'Ordre : asc ou desc.',
        ]);

        $sort = $data['sort'] ?? 'recent';
        $direction = $data['direction'] ?? ($sort === 'name' ? 'asc' : 'desc');

        $query = CustomerListQuery::base($business);
        CustomerListQuery::filter($query, $business, $data['search'] ?? null, (bool) ($data['owes'] ?? false));
        CustomerListQuery::sort($query, $sort, $direction);

        $page = $query->paginate(CustomerListQuery::PER_PAGE);
        $page->setCollection($page->getCollection()->map(fn (Customer $customer) => CustomerListQuery::present($customer)));

        return response()->json(array_merge($page->toArray(), [
            'totals' => CustomerListQuery::totals($business),
            'currency' => 'FCFA',
        ]));
    }

    /**
     * Fiche : informations, note, conversations, commandes et ventes avec leurs versements.
     */
    public function show(Customer $customer): JsonResponse
    {
        $business = $this->ownedBusiness($customer);
        $row = CustomerListQuery::base($business)->whereKey($customer->id)->firstOrFail();

        $orders = $customer->orders()
            ->with(['lines.product', 'payments'])
            ->latest()
            ->limit(self::ORDERS_LIMIT)
            ->get()
            ->each(function (Order $order) {
                $order->presentLines();
                $order->setAttribute('balance_due', $order->status === 'cancelled' ? 0 : $order->balanceDue());
            });

        $conversations = $customer->conversations()
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->get(['id', 'customer_id', 'customer_name', 'created_at', 'last_message_at'])
            ->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'customer_name' => $conversation->customer_name,
                'created_at' => $conversation->created_at?->toIso8601String(),
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'messages_count' => (int) $conversation->messages_count,
            ]);

        // Encaissé sur des commandes annulées : ni dépensé ni dû, à rembourser ou à reporter.
        $cancelledPaid = (float) $customer->orders()->where('status', 'cancelled')->sum('amount_paid');

        return response()->json([
            'customer' => CustomerListQuery::present($row) + [
                'note' => $row->note,
                'cancelled_paid' => round($cancelledPaid, 2),
                'orders_total_count' => $customer->orders()->count(),
            ],
            'conversations' => $conversations,
            'orders' => $orders,
            // Le bouton « Ajouter un versement » suit les règles de POST /orders/{order}/payments.
            'can_add_payment' => $business->moduleEnabled('orders') && $business->hasModule('orders'),
            'currency' => 'FCFA',
        ]);
    }

    /**
     * Nom choisi par le commerçant (vide : revenir au nom WhatsApp) et note libre.
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $business = $this->ownedBusiness($customer);

        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:'.Customer::NAME_MAX_LENGTH],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.Customer::NOTE_MAX_LENGTH],
        ], [
            'name.max' => 'Le nom ne doit pas dépasser '.Customer::NAME_MAX_LENGTH.' caractères.',
            'note.max' => 'La note ne doit pas dépasser '.Customer::NOTE_MAX_LENGTH.' caractères.',
        ]);

        $changes = [];
        if (array_key_exists('name', $data)) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $data['name']) ?? '');
            $changes['name'] = $name === '' ? null : $name;
        }
        if (array_key_exists('note', $data)) {
            $note = trim((string) $data['note']);
            $changes['note'] = $note === '' ? null : $note;
        }
        $customer->update($changes);

        $row = CustomerListQuery::base($business)->whereKey($customer->id)->firstOrFail();

        return response()->json([
            'message' => 'Fiche client enregistrée.',
            'customer' => CustomerListQuery::present($row) + ['note' => $row->note],
        ]);
    }

    private function ownedBusiness(Customer $customer): Business
    {
        $business = $customer->business()->firstOrFail();
        $this->checkOwnership($business);

        return $business;
    }

    /**
     * Vérifier que l'entreprise appartient à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
