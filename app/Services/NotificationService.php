<?php

namespace App\Services;

use App\Mail\OwnerNotificationMail;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Notification;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\WhatsApp\OwnerAlertService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationService
{
    public function __construct(
        private readonly OwnerAlertService $whatsAppAlerts,
    ) {
    }

    /**
     * Notifier le gérant d'une nouvelle escalade, ou d'une relance du client sur une escalade
     * encore en attente ($followUp). Une notification d'escalade non lue pour la même
     * conversation est mise à jour au lieu d'être dupliquée.
     */
    public function notifyEscalation(Escalation $escalation, bool $followUp = false): void
    {
        try {
            $conversation = $escalation->conversation;
            $businessId = $escalation->business_id ?? $conversation?->business_id;

            if ($businessId === null) {
                return;
            }

            $customer = $conversation?->customer_name ?: $conversation?->customer_phone;

            $attributes = [
                'title' => self::escalationTitle($escalation->reason, $customer, $followUp),
                'body' => $escalation->customer_question,
                'data' => array_filter([
                    'conversation_id' => $escalation->conversation_id,
                    'escalation_id' => $escalation->id,
                    'reason' => $escalation->reason,
                    'follow_up' => $followUp ?: null,
                ]),
            ];

            $existing = Notification::query()
                ->where('business_id', $businessId)
                ->where('type', 'escalation')
                ->whereNull('read_at')
                ->where('data->conversation_id', $escalation->conversation_id)
                ->latest('updated_at')
                ->first();

            if ($existing !== null) {
                $existing->fill($attributes);
                $existing->updated_at = now();
                $existing->save();
            } else {
                Notification::create($attributes + [
                    'business_id' => $businessId,
                    'type' => 'escalation',
                ]);
            }
        } catch (Throwable $e) {
            // Une notification manquée ne doit pas bloquer l'escalade elle-même.
            Log::error('NotificationService::notifyEscalation a échoué', [
                'escalation_id' => $escalation->id,
                'message' => $e->getMessage(),
            ]);
        }

        // En plus de la cloche, sans jamais la remplacer (au plus une alerte par période).
        $this->whatsAppAlerts->escalation($escalation);
    }

    /** Le rappel « Enregistrez vos statuts » part au plus une fois par période, par business. */
    public const STATUS_HINT_COOLDOWN_HOURS = 24;

    /**
     * Un client répond à un statut WhatsApp alors qu'aucun statut du jour n'est enregistré :
     * l'assistant ne sait pas de quel article il parle. Le gérant est invité à enregistrer ses statuts.
     */
    public function notifyStatusHint(Business $business, Conversation $conversation): void
    {
        try {
            if (! Cache::add("status-hint:{$business->id}", true, now()->addHours(self::STATUS_HINT_COOLDOWN_HOURS))) {
                return;
            }

            $customer = $conversation->customer_name ?: $conversation->customer_phone;

            Notification::create([
                'business_id' => $business->id,
                'type' => 'status',
                'title' => "{$customer} répond à un de vos statuts",
                'body' => "L'assistant ne sait pas de quel article il s'agit. Enregistrez vos statuts dans Causeo pour que l'assistant puisse répondre.",
                'data' => [
                    'kind' => 'status_hint',
                    'conversation_id' => $conversation->id,
                    'action_path' => '/dashboard/status',
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('NotificationService::notifyStatusHint a échoué', [
                'business_id' => $business->id,
                'exception' => $e::class,
            ]);
        }
    }

    /** Titre de la notification d'escalade, selon son motif. */
    public static function escalationTitle(?string $reason, ?string $customer, bool $followUp = false): string
    {
        $of = $customer ? " de {$customer}" : '';

        if ($followUp) {
            return "Relance{$of} : demande toujours en attente";
        }

        return match ($reason) {
            Escalation::REASON_PAYMENT_PROOF => $customer ? "Preuve de paiement de {$customer} à vérifier" : 'Preuve de paiement à vérifier',
            Escalation::REASON_PARTNERSHIP => "Proposition de partenariat{$of}",
            Escalation::REASON_PAST_ORDER => "Réclamation{$of} sur une commande ou une prestation passée",
            Escalation::REASON_COMPLAINT => "Plainte{$of} à traiter",
            Escalation::REASON_PERSONAL => "Message personnel{$of}",
            Escalation::REASON_STATUS_REPLY => "Réponse{$of} à un statut à traiter",
            default => $customer ? "Question de {$customer} à traiter" : 'Question client à traiter',
        };
    }

    /**
     * E-mail au gérant pour un vocal, un document, une vidéo ou une preuve de paiement
     * que le bot n'a pas traité, en plus de la cloche et de l'alerte WhatsApp de
     * l'escalade : il faut l'ouvrir pour répondre.
     */
    public function emailMediaEscalation(Escalation $escalation, bool $repliedToCustomer): void
    {
        try {
            $conversation = $escalation->conversation;
            $email = $escalation->business?->user?->email;

            if ($conversation === null || empty($email)) {
                return;
            }

            $customer = $conversation->customer_name ?: $conversation->customer_phone;

            [$subject, $open, $action] = match ($escalation->reason) {
                Escalation::REASON_DOCUMENT => ["Document de {$customer} à consulter", 'Ouvrez le document dans la conversation', 'Voir le document'],
                Escalation::REASON_VIDEO => ["Vidéo de {$customer} à regarder", 'Regardez la vidéo dans la conversation', 'Voir la vidéo'],
                Escalation::REASON_PAYMENT_PROOF => ["Preuve de paiement de {$customer} à vérifier", 'Vérifiez le paiement, puis répondez au client', 'Voir la capture'],
                default => ["Message vocal de {$customer} à écouter", 'Écoutez le message dans la conversation', 'Écouter le message'],
            };

            Mail::to($email)->queue(new OwnerNotificationMail(
                $subject,
                [
                    "{$escalation->customer_question}, reçu de {$customer}.",
                    ($repliedToCustomer ? "L'assistant a répondu au client que vous le recontacteriez. " : "L'assistant n'a pas répondu au client. ")
                        ."{$open}, puis répondez depuis Causeo ou WhatsApp.",
                ],
                $action,
                rtrim((string) config('app.frontend_url'), '/').'/dashboard/conversations?conversation='.$conversation->id,
            ));
        } catch (Throwable $e) {
            // Le message d'une erreur d'envoi peut contenir l'adresse : on ne garde que sa classe.
            Log::error('NotificationService::emailMediaEscalation a échoué', [
                'escalation_id' => $escalation->id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Prévenir le gérant qu'une variante passe sous son seuil ($level low_stock) ou en
     * rupture (out_of_stock) : la cloche, et un e-mail s'il l'a demandé (stock_alert_email).
     * Une alerte non lue pour la même variante est mise à jour au lieu d'être dupliquée.
     */
    public function notifyStock(ProductVariant $variant, string $level): void
    {
        try {
            $variant->loadMissing('product.business.user');
            $product = $variant->product;
            $business = $product->business;
            $label = trim($product->name.($variant->optionsLabel() !== '' ? " ({$variant->optionsLabel()})" : ''));
            $out = $level === ProductVariant::OUT_OF_STOCK;
            $count = (int) $variant->stock_quantity;

            $attributes = [
                'title' => $out ? "Rupture de stock : {$product->name}" : "Stock faible : {$product->name}",
                'body' => $out ? "{$label} : plus aucune pièce en stock." : "{$label} : il reste {$count} pièce".($count > 1 ? 's' : '').'.',
                'data' => [
                    'kind' => 'stock',
                    'level' => $level,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'action_path' => '/dashboard/catalog?product='.$product->id,
                ],
            ];

            $existing = Notification::query()
                ->where('business_id', $business->id)
                ->where('type', 'stock')
                ->whereNull('read_at')
                ->where('data->variant_id', $variant->id)
                ->first();

            if ($existing !== null) {
                $existing->fill($attributes)->save();
            } else {
                Notification::create($attributes + ['business_id' => $business->id, 'type' => 'stock']);
            }

            $email = $business->user?->email;
            if ($business->stock_alert_email && ! empty($email)) {
                Mail::to($email)->queue(new OwnerNotificationMail(
                    $attributes['title'],
                    [$attributes['body'], $out ? "L'assistant indique aux clients que l'article est en rupture." : "L'assistant indique aux clients qu'il reste les dernières pièces."],
                    'Voir le catalogue',
                    rtrim((string) config('app.frontend_url'), '/').$attributes['data']['action_path'],
                ));
            }
        } catch (Throwable $e) {
            Log::error('NotificationService::notifyStock a échoué', [
                'variant_id' => $variant->id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Notifier le gérant d'une nouvelle demande de rendez-vous.
     */
    public function notifyAppointment(Appointment $appointment): void
    {
        try {
            $customer = $appointment->customer_name ?: $appointment->customer_phone;

            $details = array_filter([
                $appointment->location,
                $appointment->participants ? "{$appointment->participants} participant".($appointment->participants > 1 ? 's' : '') : null,
                $appointment->price !== null ? number_format($appointment->price, 0, ',', ' ').' FCFA' : null,
            ]);

            $body = "{$appointment->service} — ".$appointment->requested_date->format('d/m/Y')." à {$appointment->requested_time}"
                .($details !== [] ? ', '.implode(', ', $details) : '');

            Notification::create([
                'business_id' => $appointment->business_id,
                'type' => 'appointment',
                'title' => "Demande de rendez-vous de {$customer}",
                'body' => $body,
                'data' => [
                    'conversation_id' => $appointment->conversation_id,
                    'appointment_id' => $appointment->id,
                ],
            ]);

            Log::info('NotificationService: demande de rendez-vous notifiée au gérant', [
                'business_id' => $appointment->business_id,
                'appointment_id' => $appointment->id,
            ]);
        } catch (Throwable $e) {
            // Une notification manquée ne doit pas annuler la demande.
            Log::error('NotificationService::notifyAppointment a échoué', [
                'appointment_id' => $appointment->id,
                'message' => $e->getMessage(),
            ]);
        }

        $this->whatsAppAlerts->appointment($appointment);
    }

    /**
     * Notifier le gérant d'une nouvelle commande.
     */
    public function notifyOrder(Order $order): void
    {
        try {
            $customer = $order->customer_name ?: $order->customer_phone;

            $lines = array_map(
                fn (array $item) => "{$item['quantity']} × {$item['name']}".(! empty($item['options']) ? " ({$item['options']})" : ''),
                $order->items,
            );

            $remise = match ($order->fulfillment_type) {
                'pickup' => ', retrait en boutique'.($order->pickup_time ? " ({$order->pickup_time})" : ''),
                'shipping' => $order->delivery_city ? ", expédition vers {$order->delivery_city}" : ', expédition',
                default => $order->delivery_city ? ", livraison {$order->delivery_city}" : '',
            };

            $body = implode(', ', $lines)
                .' — total '.number_format($order->total_amount, 0, ',', ' ').' FCFA'
                .$remise;

            Notification::create([
                'business_id' => $order->business_id,
                'type' => 'order',
                'title' => "Nouvelle commande de {$customer}",
                'body' => $body,
                'data' => [
                    'conversation_id' => $order->conversation_id,
                    'order_id' => $order->id,
                ],
            ]);
        } catch (Throwable $e) {
            // Une notification manquée ne doit pas annuler la commande.
            Log::error('NotificationService::notifyOrder a échoué', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }

        $this->whatsAppAlerts->order($order);
    }
}
