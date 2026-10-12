<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendOwnerWhatsAppAlert;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Escalation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alertes WhatsApp au gérant (escalade, commande, rendez-vous), envoyées depuis le
 * numéro Causeo avec des modèles approuvés. Elles complètent la notification du
 * dashboard, qui est toujours créée : une alerte manquée ne bloque jamais rien.
 */
class OwnerAlertService
{
    public const ESCALATION = 'escalation';

    public const ORDER = 'order';

    public const APPOINTMENT = 'appointment';

    /** Modèles approuvés chez Meta, par type d'alerte. */
    public const TEMPLATES = [
        self::ESCALATION => 'escalade_client',
        self::ORDER => 'nouvelle_commande',
        self::APPOINTMENT => 'nouveau_rendez_vous',
    ];

    /** Longueur maximale de la question du client dans l'alerte d'escalade. */
    public const QUESTION_MAX_LENGTH = 100;

    public function __construct(
        private readonly CauseoNotifyClient $client,
    ) {
    }

    public function escalation(Escalation $escalation): void
    {
        $businessId = $escalation->business_id ?? $escalation->conversation?->business_id;

        $this->queue(self::ESCALATION, $escalation->id, $businessId);
    }

    public function order(Order $order): void
    {
        $this->queue(self::ORDER, $order->id, $order->business_id);
    }

    public function appointment(Appointment $appointment): void
    {
        $this->queue(self::APPOINTMENT, $appointment->id, $appointment->business_id);
    }

    /**
     * Le gérant a activé les alertes, donné son accord et indiqué un numéro.
     */
    public static function ownerAccepts(?User $owner): bool
    {
        return $owner !== null
            && $owner->whatsapp_alerts_enabled === true
            && $owner->whatsapp_alerts_consented_at !== null
            && trim((string) $owner->notification_phone) !== '';
    }

    /**
     * Paramètres du modèle ({{1}}, {{2}}…) pour l'élément concerné, ou null s'il n'existe plus.
     *
     * @return list<string>|null
     */
    public function parametersFor(string $kind, string $id): ?array
    {
        return match ($kind) {
            self::ESCALATION => ($escalation = Escalation::with('conversation')->find($id)) === null ? null : [
                $this->businessName($escalation->business_id ?? $escalation->conversation?->business_id),
                self::text($escalation->customer_question, self::QUESTION_MAX_LENGTH),
            ],
            self::ORDER => ($order = Order::find($id)) === null ? null : [
                $this->businessName($order->business_id),
                self::text($this->itemsSummary($order), 200),
                self::money($order->total_amount),
            ],
            self::APPOINTMENT => ($appointment = Appointment::find($id)) === null ? null : [
                $this->businessName($appointment->business_id),
                self::text((string) $appointment->service, 100),
                self::text(self::when($appointment), 100),
            ],
            default => null,
        };
    }

    /**
     * Envoyer tout de suite un modèle au gérant.
     *
     * @param  list<string>  $parameters
     */
    public function send(User $owner, string $template, array $parameters): CauseoNotifyResult
    {
        return $this->client->sendTemplate((string) $owner->notification_phone, $template, $parameters);
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Texte d'un paramètre : Meta refuse les retours à la ligne, les tabulations et
     * plus de 4 espaces de suite, ainsi qu'un paramètre vide.
     */
    public static function text(?string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if (mb_strlen($value) > $max) {
            $value = rtrim(mb_substr($value, 0, $max - 1)).'…';
        }

        return $value === '' ? '-' : $value;
    }

    /** « 26 000 FCFA » */
    public static function money(float|int|null $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' FCFA';
    }

    /** « samedi 17 octobre à 15h », ou « samedi 17 octobre (après-midi) » si l'heure n'est pas précise. */
    public static function when(Appointment $appointment): string
    {
        $date = $appointment->requested_date?->locale('fr')->isoFormat('dddd D MMMM') ?? '';
        $time = trim((string) $appointment->requested_time);

        if (preg_match('/^(\d{1,2})\s*(?:[:hH]\s*(\d{2})?)?(?::\d{2})?$/', $time, $match) === 1) {
            $minutes = $match[2] ?? '';

            return trim("{$date} à ".(int) $match[1].'h'.($minutes !== '' && $minutes !== '00' ? $minutes : ''));
        }

        return trim($date.($time !== '' ? " ({$time})" : ''));
    }

    /**
     * Mettre l'alerte en file si le gérant l'accepte. Les escalades sont limitées à
     * une alerte par business et par période (services.causeo_notify.escalation_cooldown_minutes).
     */
    private function queue(string $kind, string $id, ?string $businessId): void
    {
        try {
            if ($businessId === null || ! $this->client->isConfigured()) {
                return;
            }

            $owner = Business::with('user')->find($businessId)?->user;

            if (! self::ownerAccepts($owner)) {
                return;
            }

            if ($kind === self::ESCALATION) {
                $minutes = max(1, (int) config('services.causeo_notify.escalation_cooldown_minutes', 15));

                if (! Cache::add("whatsapp-alerts:escalation:{$businessId}", true, now()->addMinutes($minutes))) {
                    return;
                }
            }

            SendOwnerWhatsAppAlert::dispatch($kind, $id, $businessId);
        } catch (Throwable $e) {
            Log::warning('Alerte WhatsApp au gérant non mise en file.', [
                'kind' => $kind,
                'business_id' => $businessId,
                'exception' => $e::class,
            ]);
        }
    }

    private function businessName(?string $businessId): string
    {
        return self::text($businessId ? Business::query()->whereKey($businessId)->value('name') : null, 60);
    }

    private function itemsSummary(Order $order): string
    {
        return implode(', ', array_map(
            fn (array $item) => ($item['quantity'] ?? 1).' × '.($item['name'] ?? ''),
            (array) $order->items,
        ));
    }
}
