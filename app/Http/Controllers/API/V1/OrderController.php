<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * Lister les commandes d'une entreprise, les plus récentes d'abord.
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $request->validate([
            'status' => ['nullable', Rule::in(Order::STATUSES)],
        ], [
            'status.in' => 'Le statut doit être l\'un des suivants : '.implode(', ', Order::STATUSES).'.',
        ]);

        $orders = $business->orders()
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
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
     * Vérifier que l'entreprise appartient à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }
}
