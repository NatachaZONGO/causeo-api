<?php

namespace Tests\Feature;

use App\Mail\OwnerNotificationMail;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

class MailLocalizationTest extends TestCase
{
    /** Phrases anglaises des modèles d'e-mail par défaut de Laravel. */
    private const ENGLISH_PHRASES = [
        'having trouble clicking',
        'copy and paste the URL',
        'into your web browser',
        'All rights reserved',
        'Regards,',
        'Hello!',
        'Whoops!',
    ];

    public function test_the_application_locale_is_french(): void
    {
        $this->assertSame('fr', config('app.locale'));
        $this->assertSame('fr', app()->getLocale());
    }

    public function test_no_english_phrase_remains_in_sent_emails(): void
    {
        $user = new User(['name' => 'Awa', 'email' => 'awa@example.test']);

        $user->notify(new ResetPasswordNotification('jeton'));
        $user->notify(new PasswordChangedNotification());
        Mail::to('awa@example.test')->send(new OwnerNotificationMail('Rapport hebdomadaire', ['Votre semaine en chiffres.'], 'Voir le tableau de bord', 'https://dashboard.example.test'));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(3, $messages);

        foreach ($messages as $sent) {
            /** @var SentMessage $sent */
            $email = $sent->getOriginalMessage();
            $subject = $email->getSubject();

            foreach (['html' => $email->getHtmlBody(), 'texte' => $email->getTextBody()] as $part => $body) {
                $body = html_entity_decode((string) $body, ENT_QUOTES | ENT_HTML5);
                $this->assertNotSame('', trim($body), "« {$subject} » ({$part}) est vide.");

                foreach (self::ENGLISH_PHRASES as $phrase) {
                    $this->assertStringNotContainsString($phrase, $body, "« {$subject} » ({$part}) contient « {$phrase} ».");
                }

                $this->assertStringContainsString('Tous droits réservés.', $body, "« {$subject} » ({$part})");
            }
        }

        $reset = html_entity_decode((string) $messages[0]->getOriginalMessage()->getHtmlBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('Si le bouton « Choisir un nouveau mot de passe » ne fonctionne pas, copiez et collez le lien ci-dessous', $reset);
    }
}
