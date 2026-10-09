<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email envoyé au gérant en double d'une notification du dashboard (rappels
 * d'échéance, rapport hebdomadaire).
 */
class OwnerNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines  paragraphes du message
     */
    public function __construct(
        public string $title,
        public array $lines,
        public string $actionLabel,
        public string $actionUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.owner-notification');
    }
}
