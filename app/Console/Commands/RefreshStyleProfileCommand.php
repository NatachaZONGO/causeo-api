<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\AI\ManagerReplyService;
use App\Services\AI\StyleProfileService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Établir le profil de style d'un business à partir des réponses de son gérant.
 */
class RefreshStyleProfileCommand extends Command
{
    protected $signature = 'ai:style-profile
        {business? : identifiant du business}
        {--all : tous les businesses dont le profil est dû}
        {--backfill : reprendre d\'abord les réponses du gérant déjà en base}
        {--force : recalculer même si le profil n\'est pas dû (au moins 10 réponses restent nécessaires)}';

    protected $description = 'Calcule le profil de style (tutoiement, longueur, formules, emojis…) à partir des réponses du gérant';

    public function handle(StyleProfileService $styles, ManagerReplyService $replies): int
    {
        $id = $this->argument('business');

        if ($id === null && ! $this->option('all')) {
            $this->error('Indiquez un business, ou --all.');

            return self::INVALID;
        }

        $businesses = $id !== null ? Business::query()->whereKey($id)->get() : Business::query()->get();

        if ($businesses->isEmpty()) {
            $this->error("Business introuvable : {$id}");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($businesses as $business) {
            $label = "{$business->name} ({$business->id})";

            if (! $business->learnsFromOwner()) {
                $this->line("{$label} : apprentissage du style désactivé par le gérant.");

                continue;
            }

            if ($this->option('backfill')) {
                $this->line("{$label} : ".$replies->backfill($business).' réponse(s) du gérant reprise(s).');
            }

            if (! $this->option('force') && ! $styles->isDue($business)) {
                $this->line("{$label} : profil pas encore dû ({$business->managerReplies()->count()} réponse(s) du gérant).");

                continue;
            }

            try {
                $profile = $styles->refresh($business);
            } catch (Throwable $e) {
                $this->error("{$label} : échec — ".mb_substr($e->getMessage(), 0, 300));
                $failed = true;

                continue;
            }

            if ($profile === null) {
                $this->line("{$label} : moins de ".StyleProfileService::MIN_REPLIES.' réponses du gérant, pas de profil.');

                continue;
            }

            $this->info("{$label} : profil mis à jour ({$profile['sample_size']} réponses, médiane {$profile['median_words']} mots).");
            $this->line('  '.$profile['instructions']);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
