<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
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
            'q' => ['nullable', 'string', 'max:100'],
        ], [
            'status.in' => 'Le statut doit être l\'un des suivants : '.implode(', ', Order::STATUSES).'.',
            'fulfillment_type.in' => 'Le mode de remise doit être l\'un des suivants : '.implode(', ', Order::FULFILLMENT_TYPES).'.',
            'q.max' => 'La recherche ne doit pas dépasser 100 caractères.',
        ]);

        $orders = $business->orders()
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['fulfillment_type'] ?? null, fn ($query, $type) => $query->where('fulfillment_type', $type))
            ->when(trim($data['q'] ?? ''), fn ($query, $search) => $this->search($query, $search))
            ->latest()
            ->paginate(20);

        return response()->json($orders);
    }

    /**
     * Afficher une commande.
     */
    public function show(Order $order): JsonResponse
    {
        $order->loadMissing('business');

        $this->checkOwnership($order->business);
        $this->checkOrdersModule($order->business);

        return response()->json([
            'order' => $order,
        ]);
    }

    /**
     * Changer le statut d'une commande.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $order->loadMissing('business');

        $this->checkOwnership($order->business);
        $this->checkOrdersModule($order->business, write: true);

        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
        ], [
            'status.required' => 'Le statut est obligatoire.',
            'status.in' => 'Le statut doit être l\'un des suivants : '.implode(', ', Order::STATUSES).'.',
        ]);

        $order->update(['status' => $data['status']]);

        return response()->json([
            'message' => 'Le statut de la commande a été mis à jour.',
            'order' => $order,
        ]);
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

        $itemNames = DB::getDriverName() === 'pgsql'
            ? "EXISTS (SELECT 1 FROM json_array_elements(orders.items) AS item WHERE LOWER(item->>'name') LIKE ?)"
            : "EXISTS (SELECT 1 FROM json_each(orders.items) AS item WHERE LOWER(json_extract(item.value, '$.name')) LIKE ? ESCAPE '\\')";

        $like = DB::getDriverName() === 'pgsql' ? 'LIKE ?' : "LIKE ? ESCAPE '\\'";

        $query->where(function ($query) use ($pattern, $itemNames, $like) {
            $query->whereRaw("LOWER(CAST(orders.id AS TEXT)) {$like}", [$pattern])
                ->orWhereRaw("LOWER(orders.customer_name) {$like}", [$pattern])
                ->orWhereRaw("LOWER(orders.customer_phone) {$like}", [$pattern])
                ->orWhereRaw("LOWER(orders.delivery_city) {$like}", [$pattern])
                ->orWhereRaw($itemNames, [$pattern]);
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
