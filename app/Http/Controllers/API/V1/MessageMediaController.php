<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\AdminAccessLog;
use App\Models\Message;
use App\Services\WhatsApp\MediaMessage;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Afficher une photo, un document, une vidéo ou un autocollant d'une conversation : le
 * fichier est relu chez Meta à chaque demande (rien n'est stocké chez Causeo). Réservé
 * au gérant du business et aux admins ; chaque consultation par un admin est
 * journalisée (admin_access_logs).
 */
class MessageMediaController extends Controller
{
    /** Extensions des fichiers sans nom (photo, vidéo, autocollant). */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'application/pdf' => 'pdf',
    ];

    public function show(Message $message, WhatsAppMediaService $media): Response|JsonResponse
    {
        $business = $message->conversation?->business;
        $user = auth()->user();
        $isOwner = $business !== null && $business->user_id === $user->id;

        abort_if($business === null || (! $isOwner && ! $user->is_admin), 403, 'Ce message ne vous appartient pas.');

        $type = $message->metadata['type'] ?? null;
        $mediaId = $message->metadata['media_id'] ?? null;
        abort_if(! in_array($type, MediaMessage::TYPES, true) || empty($mediaId), 404, 'Ce message ne contient pas de fichier.');

        if (! $isOwner) {
            // Qui a ouvert quel fichier de quel business, et quand ; jamais le contenu.
            AdminAccessLog::create([
                'admin_id' => $user->id,
                'action' => AdminAccessLog::MEDIA_VIEWED,
                'message_id' => $message->id,
                'business_id' => $business->id,
                'conversation_id' => $message->conversation_id,
            ]);
        }

        try {
            $file = $media->download($business, (string) $mediaId);
        } catch (Throwable $e) {
            Log::info('MessageMediaController: fichier indisponible chez Meta.', ['message_id' => $message->id, 'exception' => $e::class]);

            return response()->json([
                'message' => 'Ce fichier n\'est plus disponible chez WhatsApp (les médias reçus y sont conservés environ 30 jours).',
            ], 410);
        }

        // Un document s'enregistre sous son nom ; photos et vidéos s'affichent dans le dashboard.
        $disposition = $type === 'document' ? 'attachment' : 'inline';

        return response($file['body'], 200, [
            'Content-Type' => $file['mime_type'],
            'Content-Length' => (string) $file['size'],
            'Content-Disposition' => $disposition.'; filename="'.$this->filename($message, $type, $file['mime_type']).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filename(Message $message, string $type, string $mimeType): string
    {
        $name = (string) ($message->metadata['filename'] ?? '');

        if ($name === '') {
            $extension = self::EXTENSIONS[strtolower(trim(explode(';', $mimeType)[0]))] ?? 'bin';
            $name = ['image' => 'photo', 'video' => 'video', 'sticker' => 'autocollant'][$type] ?? 'document';
            $name .= ".{$extension}";
        }

        // En-tête : ni guillemets ni caractères hors ASCII.
        return str_replace(['"', '\\'], '', Str::ascii($name));
    }
}
