<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Models\Order;
use App\Services\Customers\CustomerService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tient la liste des clients à jour, quel que soit le chemin qui crée la conversation ou la
 * commande (webhook Meta, écho d'un message du gérant, pont Express, assistant, comptoir).
 * Une erreur ici ne doit jamais faire perdre un message ni une vente : elle est journalisée,
 * sans nom ni numéro, et le lien sera refait au prochain enregistrement.
 */
class CustomerLinkObserver
{
    public function __construct(private readonly CustomerService $customers) {}

    public function saved(Conversation|Order $model): void
    {
        try {
            if ($model instanceof Conversation) {
                // Nouveau numéro, ou nom de profil WhatsApp changé.
                if ($model->customer_id === null || $model->wasChanged(['customer_name', 'customer_phone'])) {
                    $this->customers->linkConversation($model);
                }
            } elseif ($model->customer_id === null && ($model->wasRecentlyCreated || $model->wasChanged('customer_phone'))) {
                $this->customers->linkOrder($model);
            }
        } catch (Throwable $e) {
            Log::warning('Clients : rattachement impossible', [
                'model' => class_basename($model),
                'id' => $model->getKey(),
                // La classe seulement : le message d'une erreur SQL contiendrait le numéro.
                'error' => $e::class,
            ]);
        }
    }
}
