<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lancées par « php artisan schedule:run » (cron du VPS, deploy/scheduler.sh). Les deux commandes
// sont idempotentes : un passage manqué est rattrapé au suivant.
Schedule::command('billing:sync')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('billing:weekly-report')->hourlyAt(5)->withoutOverlapping()->onOneServer();
