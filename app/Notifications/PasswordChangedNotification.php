<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation envoyée après une réinitialisation du mot de passe.
 */
class PasswordChangedNotification extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Votre mot de passe a été modifié')
            ->greeting('Bonjour,')
            ->line('Le mot de passe de votre compte Causeo vient d\'être modifié. Par sécurité, tous vos appareils ont été déconnectés : reconnectez-vous avec votre nouveau mot de passe.')
            ->action('Se connecter', rtrim((string) config('app.frontend_url'), '/').'/auth/login')
            ->line('Si vous n\'êtes pas à l\'origine de cette modification, réinitialisez votre mot de passe dès maintenant et contactez-nous.')
            ->salutation('L\'équipe Causeo');
    }
}
