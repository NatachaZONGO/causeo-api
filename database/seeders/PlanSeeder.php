<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /** Temps qu'un gérant passerait à rédiger une réponse lui-même. */
    public const MINUTES_PER_REPLY = 2;

    /**
     * Formules Causeo. Relancé à chaque déploiement (docker-entrypoint.sh) :
     * modifier une formule ici la met à jour en base.
     */
    public function run(): void
    {
        $allModules = Business::MODULES;
        $available = 'Votre vendeuse disponible 24 h/24';
        $learning = 'L\'assistant apprend de vos réponses';
        $modules = 'Commandes et rendez-vous pris directement sur WhatsApp';

        $plans = [
            [
                'slug' => Plan::FREE,
                'name' => 'Gratuit',
                'description' => 'Pour découvrir Causeo et répondre aux premières questions de vos clients.',
                'price_fcfa' => 0,
                'period_days' => null,
                'reply_limit' => 10,
                'reply_limit_period' => 'day',
                'document_limit' => 1,
                'modules' => [],
                'learning' => false,
                'features' => [
                    $this->hoursSaved(10 * 30),
                    "{$available}, pour les 10 premiers clients du jour",
                    '1 document pour informer l\'assistant',
                ],
                'badge' => null,
                'highlight_daily_price' => false,
                'is_public' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => Plan::STARTER,
                'name' => 'Starter',
                'description' => 'Pour les petits commerces qui veulent répondre à chaque client.',
                'price_fcfa' => 9900,
                'period_days' => 30,
                'reply_limit' => 750,
                'reply_limit_period' => 'period',
                'document_limit' => null,
                'modules' => [],
                'learning' => true,
                'features' => [
                    $this->hoursSaved(750),
                    $available,
                    $learning,
                    'Documents illimités',
                ],
                'badge' => null,
                'highlight_daily_price' => true,
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => Plan::PRO,
                'name' => 'Pro',
                'description' => 'Pour vendre et prendre des rendez-vous directement sur WhatsApp.',
                'price_fcfa' => 19900,
                'period_days' => 30,
                'reply_limit' => 2000,
                'reply_limit_period' => 'period',
                'document_limit' => null,
                'modules' => $allModules,
                'learning' => true,
                'features' => [
                    $this->hoursSaved(2000),
                    $available,
                    $modules,
                    $learning,
                    'Documents illimités',
                ],
                'badge' => 'Le plus choisi',
                'highlight_daily_price' => true,
                'is_public' => true,
                'sort_order' => 3,
            ],
            [
                'slug' => Plan::BUSINESS,
                'name' => 'Business',
                'description' => 'Pour les entreprises qui reçoivent beaucoup de messages.',
                'price_fcfa' => 44900,
                'period_days' => 30,
                'reply_limit' => 6000,
                'reply_limit_period' => 'period',
                'document_limit' => null,
                'modules' => $allModules,
                'learning' => true,
                'features' => [
                    $this->hoursSaved(6000),
                    $available,
                    $modules,
                    $learning,
                    'Documents illimités',
                ],
                'badge' => null,
                'highlight_daily_price' => true,
                'is_public' => true,
                'sort_order' => 4,
            ],
            [
                'slug' => Plan::INTERNAL,
                'name' => 'Interne',
                'description' => 'Formule réservée aux comptes Causeo (sans limite ni échéance).',
                'price_fcfa' => 0,
                'period_days' => null,
                'reply_limit' => null,
                'reply_limit_period' => null,
                'document_limit' => null,
                'modules' => $allModules,
                'learning' => true,
                'features' => [],
                'badge' => null,
                'highlight_daily_price' => false,
                'is_public' => false,
                'sort_order' => 99,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan + ['is_active' => true]);
        }
    }

    /**
     * « Jusqu'à 66 h économisées par mois (2 000 réponses) », à raison de
     * MINUTES_PER_REPLY minutes par réponse.
     */
    private function hoursSaved(int $repliesPerMonth): string
    {
        $hours = intdiv($repliesPerMonth * self::MINUTES_PER_REPLY, 60);

        return 'Jusqu\'à '.number_format($hours, 0, ',', ' ').' h économisées par mois ('
            .number_format($repliesPerMonth, 0, ',', ' ').' réponses automatiques)';
    }
}
