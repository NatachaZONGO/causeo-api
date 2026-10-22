<?php

namespace App\Console\Commands;

use App\Models\Escalation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Expirer les escalades restées en attente plus de 24 h (sans apprentissage).
 * Planifiée toutes les heures ; avec --cleanup (à lancer une fois), ne garde aussi
 * en attente que la plus récente de chaque conversation.
 */
class ExpireEscalations extends Command
{
    protected $signature = 'escalations:expire
        {--cleanup : ne garder en attente que l\'escalade la plus récente de chaque conversation}';

    protected $description = 'Passe en « expirée » les escalades en attente depuis plus de 24 h';

    public function handle(): int
    {
        $expired = Escalation::query()
            ->where('status', Escalation::PENDING)
            ->where('created_at', '<', now()->subHours(Escalation::EXPIRES_AFTER_HOURS))
            ->update(['status' => Escalation::EXPIRED, 'updated_at' => now()]);

        $this->info("{$expired} escalade(s) en attente depuis plus de ".Escalation::EXPIRES_AFTER_HOURS.' h expirée(s).');

        if ($this->option('cleanup')) {
            $superseded = $this->keepOnlyLatestPerConversation();
            $this->info("{$superseded} escalade(s) plus ancienne(s) qu'une autre en attente dans la même conversation expirée(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Dans chaque conversation, toutes les escalades en attente sauf la plus récente.
     */
    private function keepOnlyLatestPerConversation(): int
    {
        $latest = Escalation::query()
            ->where('status', Escalation::PENDING)
            ->orderBy('conversation_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'conversation_id'])
            ->unique('conversation_id')
            ->pluck('id');

        return DB::table('escalations')
            ->where('status', Escalation::PENDING)
            ->whereNotIn('id', $latest)
            ->update(['status' => Escalation::EXPIRED, 'updated_at' => now()]);
    }
}
