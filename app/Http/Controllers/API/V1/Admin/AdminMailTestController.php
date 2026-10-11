<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * TEMPORAIRE : diagnostic de l'envoi des e-mails en production. À retirer une
 * fois le SMTP vérifié.
 */
class AdminMailTestController extends Controller
{
    /**
     * Envoyer tout de suite (sans file ni afterResponse) un e-mail de test à
     * l'admin connecté, et renvoyer la configuration utilisée, sans le mot de passe.
     */
    public function send(Request $request): JsonResponse
    {
        $mailer = (string) config('mail.default');
        $settings = (array) config("mail.mailers.{$mailer}", []);

        $config = [
            'mailer' => $mailer,
            'transport' => $settings['transport'] ?? null,
            'host' => $settings['host'] ?? null,
            'port' => $settings['port'] ?? null,
            'scheme' => $settings['scheme'] ?? null,
            'username_set' => ! empty($settings['username']),
            'password_set' => ! empty($settings['password']),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ];

        $to = (string) $request->user()->email;
        $startedAt = microtime(true);

        try {
            Mail::raw(
                'E-mail de test envoyé depuis l\'API Causeo le '.now()->format('d/m/Y à H:i:s').' (UTC). Si vous le recevez, l\'envoi des e-mails fonctionne.',
                fn ($message) => $message->to($to)->subject('Causeo : e-mail de test'),
            );
        } catch (Throwable $e) {
            return response()->json([
                'sent' => false,
                'to' => $to,
                ...$config,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                    'previous' => $e->getPrevious()?->getMessage(),
                ],
            ], 502);
        }

        return response()->json([
            // Avec le mailer « log » ou « array », rien ne part réellement.
            'sent' => true,
            'delivered_by_network' => ! in_array($config['transport'], ['log', 'array'], true),
            'to' => $to,
            ...$config,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
