<?php

namespace App\Console\Commands;

use App\Services\Billing\WeeklyReportService;
use Illuminate\Console\Command;

class SendWeeklyReports extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:weekly-report';

    /**
     * @var string
     */
    protected $description = 'Envoie aux gérants le rapport de la semaine écoulée (à partir du lundi 8 h)';

    public function handle(WeeklyReportService $reports): int
    {
        $sent = $reports->run();

        $this->info("Rapports envoyés : {$sent}.");

        return self::SUCCESS;
    }
}
