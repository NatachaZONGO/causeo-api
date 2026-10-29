<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WhatsApp\OwnerAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Réglages des alertes WhatsApp du gérant connecté (numéro, option, consentement),
 * et envoi d'une alerte de test.
 */
class WhatsAppAlertController extends Controller
{
    /**
     * Texte que le gérant accepte, affiché tel quel à côté de la case à cocher. Sa version est
     * enregistrée avec la date du consentement : changer le texte demande une nouvelle version.
     */
    public const CONSENT_TEXT = "J'accepte de recevoir sur ce numéro WhatsApp les alertes de Causeo (questions de clients à traiter, nouvelles commandes, nouveaux rendez-vous). Je peux les désactiver à tout moment dans mes réglages.";

    public const CONSENT_VERSION = '2026-10-29';

    /** Exemple envoyé par l'alerte de test (modèle escalade_client). */
    public const TEST_QUESTION = 'Bonjour, livrez-vous à Koudougou demain ?';

    public function __construct(
        private readonly OwnerAlertService $alerts,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->settings($request->user()));
    }

    /**
     * Mettre à jour le numéro, l'option et le consentement. Le consentement porte sur
     * un numéro : en changer le retire, sauf s'il est redonné dans la même requête.
     * Les alertes ne peuvent être activées qu'avec un numéro et un consentement.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notification_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'whatsapp_alerts_enabled' => ['sometimes', 'boolean'],
            'consent' => ['sometimes', 'boolean'],
        ], [
            'whatsapp_alerts_enabled.boolean' => 'L\'option des alertes WhatsApp doit valoir vrai ou faux.',
            'consent.boolean' => 'Le consentement doit valoir vrai ou faux.',
        ]);

        /** @var User $user */
        $user = $request->user();

        if (array_key_exists('notification_phone', $data)) {
            $phone = $this->normalizePhone($data['notification_phone']);

            if ($phone !== $user->notification_phone) {
                $user->notification_phone = $phone;
                $user->whatsapp_alerts_consented_at = null;
            }
        }

        if (array_key_exists('consent', $data)) {
            $user->whatsapp_alerts_consented_at = $data['consent'] ? now() : null;
            $user->whatsapp_alerts_consent_version = $data['consent'] ? self::CONSENT_VERSION : null;
        }

        if (array_key_exists('whatsapp_alerts_enabled', $data)) {
            $user->whatsapp_alerts_enabled = (bool) $data['whatsapp_alerts_enabled'];
        }

        $complete = $user->notification_phone !== null && $user->whatsapp_alerts_consented_at !== null;

        if (($data['whatsapp_alerts_enabled'] ?? false) && ! $complete) {
            throw ValidationException::withMessages([
                'whatsapp_alerts_enabled' => [$user->notification_phone === null
                    ? 'Indiquez le numéro WhatsApp qui recevra les alertes.'
                    : 'Acceptez de recevoir les alertes WhatsApp de Causeo sur ce numéro pour les activer.'],
            ]);
        }

        if (! $complete) {
            $user->whatsapp_alerts_enabled = false;
        }

        $user->save();

        return response()->json($this->settings($user));
    }

    /**
     * Activation en un clic (étape de l'inscription) : numéro (prérempli avec suggested_phone) et
     * consentement explicite, obligatoire. Le consentement est enregistré avec sa date et la version
     * du texte accepté ; les alertes sont activées.
     */
    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notification_phone' => ['required', 'string', 'max:30'],
            'consent' => ['accepted'],
        ], [
            'notification_phone.required' => 'Indiquez le numéro WhatsApp qui recevra les alertes.',
            'consent.accepted' => 'Cochez la case pour accepter de recevoir les alertes WhatsApp de Causeo sur ce numéro.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'notification_phone' => $this->normalizePhone($data['notification_phone']),
            'whatsapp_alerts_consented_at' => now(),
            'whatsapp_alerts_consent_version' => self::CONSENT_VERSION,
            'whatsapp_alerts_enabled' => true,
        ])->save();

        return response()->json($this->settings($user));
    }

    /**
     * Envoyer tout de suite l'alerte escalade_client avec des valeurs d'exemple, et
     * renvoyer la réponse de Meta ou son erreur exacte.
     */
    public function test(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->alerts->isConfigured()) {
            return response()->json(['message' => 'Les alertes WhatsApp ne sont pas encore configurées sur Causeo.'], 503);
        }

        if ($user->notification_phone === null || $user->whatsapp_alerts_consented_at === null) {
            return response()->json([
                'message' => 'Indiquez un numéro et acceptez de recevoir les alertes WhatsApp avant de tester.',
            ], 422);
        }

        $template = OwnerAlertService::TEMPLATES[OwnerAlertService::ESCALATION];
        $parameters = [
            OwnerAlertService::text($user->businesses()->value('name') ?: 'Votre boutique', 60),
            OwnerAlertService::text(self::TEST_QUESTION, OwnerAlertService::QUESTION_MAX_LENGTH),
        ];

        $result = $this->alerts->send($user, $template, $parameters);

        return response()->json([
            'sent' => $result->ok,
            'template' => $template,
            'parameters' => $parameters,
            'status' => $result->status,
            ...($result->ok ? ['meta' => $result->response] : ['error' => $result->error]),
        ], $result->ok ? 200 : 502);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(User $user): array
    {
        return [
            'notification_phone' => $user->notification_phone,
            'whatsapp_alerts_enabled' => (bool) $user->whatsapp_alerts_enabled,
            'whatsapp_alerts_consented_at' => $user->whatsapp_alerts_consented_at?->toIso8601String(),
            'whatsapp_alerts_consent_version' => $user->whatsapp_alerts_consent_version,
            'alerts_active' => OwnerAlertService::ownerAccepts($user) && $this->alerts->isConfigured(),
            'service_available' => $this->alerts->isConfigured(),
            // Étape d'activation : numéro à préremplir et texte exact à faire accepter.
            'suggested_phone' => $this->suggestedPhone($user),
            'consent_text' => self::CONSENT_TEXT,
            'consent_version' => self::CONSENT_VERSION,
        ];
    }

    /**
     * Numéro à préremplir : celui des alertes, sinon le WhatsApp ou le téléphone du gérant, sinon
     * celui de son entreprise ; au format international, ou null.
     */
    private function suggestedPhone(User $user): ?string
    {
        foreach ([$user->notification_phone, $user->whatsapp_phone, $user->phone, $user->businesses()->value('phone')] as $candidate) {
            try {
                $phone = $this->normalizePhone(is_string($candidate) ? $candidate : null);
            } catch (ValidationException) {
                continue;
            }

            if ($phone !== null) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * Format international : « +226 70 11 22 33 », « 0022670112233 » → « +22670112233 ».
     */
    private function normalizePhone(?string $phone): ?string
    {
        $phone = preg_replace('/[\s.\-()]/', '', (string) $phone);

        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $phone) !== 1) {
            throw ValidationException::withMessages([
                'notification_phone' => ['Le numéro doit être au format international, par exemple +226 70 11 22 33.'],
            ]);
        }

        return $phone;
    }
}
