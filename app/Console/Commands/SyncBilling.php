<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingSyncService;
use Illuminate\Console\Command;

class SyncBilling extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:sync';

    /**
     * @var string
     */
    protected $description = 'Enregistre les statuts d\'abonnement calculés à partir des dates et envoie les rappels d\'échéance';

    public function handle(BillingSyncService $sync): int
    {
        $stats = $sync->run();

        $this->info("Abonnements vérifiés : {$stats['checked']}, mis à jour : {$stats['updated']}, rappels envoyés : {$stats['reminders']}.");

        return self::SUCCESS;
    }
}
