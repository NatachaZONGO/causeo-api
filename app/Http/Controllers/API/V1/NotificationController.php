<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * Lister les notifications d'une entreprise, les plus récentes d'abord.
     */
    public function index(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $notifications = $business->notifications()
            ->orderByDesc('updated_at')
            ->paginate(20);

        return response()->json([
            'unread_count' => $business->notifications()->whereNull('read_at')->count(),
            ...$notifications->toArray(),
        ]);
    }

    /**
     * Marquer une notification comme lue.
     */
    public function read(Notification $notification): JsonResponse
    {
        $notification->loadMissing('business');

        $this->checkOwnership($notification->business);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'notification' => $notification,
        ]);
    }

    /**
     * Marquer toutes les notifications d'une entreprise comme lues.
     */
    public function readAll(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $updated = $business->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Toutes les notifications ont été marquées comme lues.',
            'updated' => $updated,
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
