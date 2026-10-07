<?php

namespace App\Services;

use App\Models\Escalation;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    /**
     * Notifier le gérant d'une nouvelle escalade. Une notification d'escalade
     * non lue pour la même conversation est mise à jour au lieu d'être dupliquée.
     */
    public function notifyEscalation(Escalation $escalation): void
    {
        try {
            $conversation = $escalation->conversation;
            $businessId = $escalation->business_id ?? $conversation?->business_id;

            if ($businessId === null) {
                return;
            }

            $customer = $conversation?->customer_name ?: $conversation?->customer_phone;

            $attributes = [
                'title' => $customer ? "Question de {$customer} à traiter" : 'Question client à traiter',
                'body' => $escalation->customer_question,
                'data' => [
                    'conversation_id' => $escalation->conversation_id,
                    'escalation_id' => $escalation->id,
                ],
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

                return;
            }

            Notification::create($attributes + [
                'business_id' => $businessId,
                'type' => 'escalation',
            ]);
        } catch (Throwable $e) {
            // Une notification manquée ne doit pas bloquer l'escalade elle-même.
            Log::error('NotificationService::notifyEscalation a échoué', [
                'escalation_id' => $escalation->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
