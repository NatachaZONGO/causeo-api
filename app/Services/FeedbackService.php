<?php

namespace App\Services;

use App\Mail\OwnerNotificationMail;
use App\Models\Feedback;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Retours des gérants : enregistrement, notifications aux admins à l'envoi, et au
 * gérant quand un admin répond ou résout. Une notification manquée ne bloque rien.
 */
class FeedbackService
{
    /** Longueur du début du message repris dans les notifications. */
    public const EXCERPT_LENGTH = 140;

    /** Pages du dashboard vers lesquelles pointent les e-mails. */
    public const ADMIN_PATH = '/admin/feedback';

    public const OWNER_PATH = '/dashboard/feedback';

    /**
     * @param  array<string, mixed>  $data  type, message, page, context, business_id
     */
    public function submit(User $user, array $data, ?UploadedFile $screenshot): Feedback
    {
        $path = $screenshot?->storeAs(
            "feedback/{$user->id}",
            Str::uuid().'.'.($screenshot->extension() ?: 'png'),
            'supabase_documents',
        );

        $feedback = Feedback::create([
            'user_id' => $user->id,
            'business_id' => $data['business_id'] ?? null,
            'type' => $data['type'],
            'message' => $data['message'],
            'page' => $data['page'] ?? null,
            'context' => $data['context'] ?? null,
            'screenshot_path' => $path ?: null,
        ]);

        $this->notifyAdmins($feedback, $user);

        return $feedback;
    }

    /**
     * Changer le statut et/ou répondre. Le gérant est prévenu d'une nouvelle réponse
     * ou du passage en « résolu ».
     *
     * @param  array{status?: string, admin_reply?: ?string}  $changes
     */
    public function update(Feedback $feedback, array $changes): Feedback
    {
        $resolved = isset($changes['status']) && $changes['status'] === 'resolu' && $feedback->status !== 'resolu';

        if (array_key_exists('status', $changes)) {
            $feedback->status = $changes['status'];
        }

        $replied = false;

        if (array_key_exists('admin_reply', $changes)) {
            $reply = trim((string) $changes['admin_reply']);
            $reply = $reply === '' ? null : $reply;
            $replied = $reply !== null && $reply !== $feedback->admin_reply;

            $feedback->admin_reply = $reply;
            $feedback->replied_at = $reply === null ? null : ($replied ? now() : $feedback->replied_at);
        }

        $feedback->save();

        if ($replied || $resolved) {
            $this->notifyOwner($feedback, $replied, $resolved);
        }

        return $feedback;
    }

    public static function excerpt(?string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $text)), self::EXCERPT_LENGTH, '…');
    }

    private function notifyAdmins(Feedback $feedback, User $author): void
    {
        $type = Feedback::TYPE_LABELS[$feedback->type] ?? $feedback->type;
        $title = "Nouveau retour : {$type}";
        $from = $author->name ?: $author->email;
        $body = "{$from} : ".self::excerpt($feedback->message);

        try {
            Notification::create([
                'business_id' => $feedback->business_id,
                'audience' => Notification::ADMIN,
                'type' => 'feedback',
                'title' => $title,
                'body' => $body,
                'data' => ['kind' => 'feedback_submitted', 'feedback_id' => $feedback->id, 'feedback_type' => $feedback->type],
            ]);
        } catch (Throwable $e) {
            Log::error('FeedbackService: notification admin impossible', [
                'feedback_id' => $feedback->id,
                'message' => $e->getMessage(),
            ]);
        }

        $admins = User::query()->where('is_admin', true)->whereNotNull('email')->pluck('email');

        foreach ($admins as $email) {
            $this->mail($email, $feedback, new OwnerNotificationMail(
                $title,
                [$body, 'Page : '.($feedback->page ?: 'non précisée').'.'],
                'Voir le retour',
                $this->url(self::ADMIN_PATH),
            ));
        }
    }

    private function notifyOwner(Feedback $feedback, bool $replied, bool $resolved): void
    {
        $owner = $feedback->user;

        if ($owner === null) {
            return;
        }

        $type = mb_strtolower(Feedback::TYPE_LABELS[$feedback->type] ?? $feedback->type);
        $title = $resolved ? 'Votre retour a été résolu' : 'Réponse à votre retour';
        $lines = [
            "Votre {$type} : « ".self::excerpt($feedback->message).' »',
            ...($replied ? ['Réponse de l\'équipe Causeo : '.$feedback->admin_reply] : []),
        ];

        // La cloche est rattachée à un business : celui du retour, sinon le premier du gérant.
        $businessId = $feedback->business_id ?? $owner->businesses()->value('id');

        if ($businessId !== null) {
            try {
                Notification::create([
                    'business_id' => $businessId,
                    'type' => 'feedback',
                    'title' => $title,
                    'body' => $replied ? self::excerpt($feedback->admin_reply) : "Votre {$type} a été marqué comme résolu.",
                    'data' => [
                        'kind' => $resolved ? 'feedback_resolved' : 'feedback_replied',
                        'feedback_id' => $feedback->id,
                    ],
                ]);
            } catch (Throwable $e) {
                Log::error('FeedbackService: notification gérant impossible', [
                    'feedback_id' => $feedback->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (! empty($owner->email)) {
            $this->mail($owner->email, $feedback, new OwnerNotificationMail($title, $lines, 'Voir mes retours', $this->url(self::OWNER_PATH)));
        }
    }

    private function mail(string $email, Feedback $feedback, OwnerNotificationMail $mail): void
    {
        try {
            Mail::to($email)->queue($mail);
        } catch (Throwable $e) {
            // Le message d'une erreur d'envoi peut contenir l'adresse : on ne garde que sa classe.
            Log::error('FeedbackService: e-mail impossible', [
                'feedback_id' => $feedback->id,
                'exception' => $e::class,
            ]);
        }
    }

    private function url(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }
}
