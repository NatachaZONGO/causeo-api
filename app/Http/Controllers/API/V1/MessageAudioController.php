<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\AdminAccessLog;
use App\Models\Message;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Réécouter un message vocal depuis le dashboard : le fichier est relu chez Meta à
 * chaque demande (rien n'est stocké chez Causeo). Réservé au gérant du business et
 * aux admins ; chaque écoute par un admin est journalisée (admin_access_logs).
 */
class MessageAudioController extends Controller
{
    public function show(Message $message, WhatsAppMediaService $media): Response|JsonResponse
    {
        $business = $message->conversation?->business;
        $user = auth()->user();
        $isOwner = $business !== null && $business->user_id === $user->id;

        abort_if($business === null || (! $isOwner && ! $user->is_admin), 403, 'Ce message ne vous appartient pas.');

        $mediaId = $message->metadata['media_id'] ?? null;
        abort_if(($message->metadata['type'] ?? null) !== 'audio' || empty($mediaId), 404, 'Ce message n\'est pas un message vocal.');

        if (! $isOwner) {
            // Qui a écouté quel vocal de quel business, et quand ; jamais le contenu.
            AdminAccessLog::create([
                'admin_id' => $user->id,
                'action' => AdminAccessLog::VOICE_MESSAGE_LISTENED,
                'message_id' => $message->id,
                'business_id' => $business->id,
            ]);
        }

        try {
            $file = $media->download($business, (string) $mediaId);
        } catch (Throwable $e) {
            Log::info('MessageAudioController: vocal indisponible chez Meta.', ['message_id' => $message->id, 'exception' => $e::class]);

            return response()->json([
                'message' => 'Ce message vocal n\'est plus disponible chez WhatsApp (les médias reçus y sont conservés environ 30 jours).',
            ], 410);
        }

        return response($file['body'], 200, [
            'Content-Type' => $file['mime_type'],
            'Content-Length' => (string) $file['size'],
            'Content-Disposition' => 'inline; filename="message-vocal.ogg"',
        ]);
    }
}
