<?php

namespace App\Console\Commands;

use App\Services\Sales\OrderStatusService;
use Illuminate\Console\Command;

/**
 * Annuler les commandes de l'assistant restées « new » au-delà du délai de leur entreprise
 * (order_expiry_hours, 24 h par défaut) et remettre leurs articles en stock. Une commande avec
 * une preuve de paiement n'est jamais annulée : le gérant est prévenu à la place. Planifiée
 * toutes les heures ; idempotente.
 */
class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Annule les commandes WhatsApp non confirmées à temps et remet leurs articles en stock';

    public function handle(OrderStatusService $statuses): int
    {
        $result = $statuses->expireStale();

        $this->info("{$result['expired']} commande(s) non confirmée(s) annulée(s), {$result['held']} gardée(s) (preuve de paiement reçue, gérant prévenu).");

        return self::SUCCESS;
    }
}
