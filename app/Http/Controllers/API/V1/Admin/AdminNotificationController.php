<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;

/**
 * Notifications destinées aux admins Causeo (paiements à valider, etc.).
 */
class AdminNotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $notifications = $this->query()
            ->with('business:id,name')
            ->orderByDesc('updated_at')
            ->paginate(20);

        return response()->json([
            'unread_count' => $this->query()->whereNull('read_at')->count(),
            ...$notifications->toArray(),
        ]);
    }

    public function read(Notification $notification): JsonResponse
    {
        abort_unless($notification->audience === Notification::ADMIN, 404);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['notification' => $notification]);
    }

    public function readAll(): JsonResponse
    {
        $updated = $this->query()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Toutes les notifications ont été marquées comme lues.',
            'updated' => $updated,
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Notification>
     */
    private function query()
    {
        return Notification::query()->where('audience', Notification::ADMIN);
    }
}
