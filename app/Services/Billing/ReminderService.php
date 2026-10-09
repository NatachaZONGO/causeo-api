<?php

namespace App\Services\Billing;

use App\Mail\OwnerNotificationMail;
use App\Models\Business;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envoi unique d'un rappel au gérant : notification dans le dashboard et email.
 * La ligne subscription_reminders est réservée d'abord (index unique), si bien
 * qu'un rappel déjà envoyé, ou en cours d'envoi par un autre processus, est ignoré.
 */
class ReminderService
{
    /**
     * @param  list<string>  $lines  paragraphes ; la notification les reprend bout à bout
     * @param  array<string, mixed>  $data
     * @return bool true si le rappel vient d'être envoyé
     */
    public function send(Business $business, string $kind, string $periodKey, string $title, array $lines, array $data = [], string $path = '/dashboard/billing', string $actionLabel = 'Voir mon abonnement'): bool
    {
        $reserved = DB::table('subscription_reminders')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'business_id' => $business->id,
            'kind' => $kind,
            'period_key' => $periodKey,
            'sent_at' => now(),
        ]);

        if ($reserved === 0) {
            return false;
        }

        try {
            Notification::create([
                'business_id' => $business->id,
                'type' => 'billing',
                'title' => $title,
                'body' => implode(' ', $lines),
                'data' => ['kind' => $kind, 'period_key' => $periodKey, ...$data],
            ]);
        } catch (Throwable $e) {
            Log::error('ReminderService: notification impossible', [
                'business_id' => $business->id,
                'kind' => $kind,
                'message' => $e->getMessage(),
            ]);
        }

        $email = $business->user?->email;

        if (! empty($email)) {
            try {
                Mail::to($email)->send(new OwnerNotificationMail(
                    $title,
                    $lines,
                    $actionLabel,
                    rtrim((string) config('app.frontend_url'), '/').$path,
                ));
            } catch (Throwable $e) {
                // Le message d'une erreur SMTP peut contenir l'adresse : on ne garde que sa classe.
                Log::error('ReminderService: email impossible', [
                    'business_id' => $business->id,
                    'kind' => $kind,
                    'exception' => $e::class,
                ]);
            }
        }

        return true;
    }
}
