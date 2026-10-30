<?php

namespace App\Services\Admin;

use App\Models\Business;
use App\Models\Subscription;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Suivi des inscrits (super admin) : utilisateurs non admin avec leur première entreprise,
 * l'étape atteinte, l'usage du bot et la dernière relance, le tout calculé en SQL
 * (sous-requêtes) : un nombre de requêtes fixe quelle que soit la page, et des filtres
 * appliqués avant la pagination.
 */
final class SignupListQuery
{
    /** Étapes, de la moins à la plus avancée : un inscrit est à la plus avancée qu'il a atteinte. */
    public const STAGES = ['registered', 'business_created', 'info_added', 'whatsapp_connected', 'bot_active', 'paying'];

    /** Fenêtre de l'usage du bot (messages traités, étape bot_active) et des essais qui finissent bientôt. */
    public const WINDOW_DAYS = 7;

    /**
     * @param  array{stage?: ?string, search?: ?string, trial_ending?: bool, followup_due?: bool, from?: ?string, to?: ?string}  $filters
     */
    public static function build(array $filters): Builder
    {
        $query = DB::query()->fromSub(self::staged(), 'signups');

        if (! empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            self::search($query, $search);
        }

        if (! empty($filters['trial_ending'])) {
            $query->whereBetween('trial_ends_at', [now(), now()->addDays(self::WINDOW_DAYS)]);
        }

        if (! empty($filters['followup_due'])) {
            $query->whereRaw(self::followupDueSql(), self::followupDueBindings());
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Compteurs de tous les inscrits, sans filtre (cartes du dashboard) : un par étape, plus
     * les essais qui finissent et les relances dues, en une seule requête.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $query = DB::query()->fromSub(self::staged(), 'signups');

        foreach (self::STAGES as $stage) {
            $query->selectRaw("COUNT(CASE WHEN stage = ? THEN 1 END) as {$stage}", [$stage]);
        }

        $row = (array) $query
            ->selectRaw('COUNT(CASE WHEN trial_ends_at BETWEEN ? AND ? THEN 1 END) as trial_ending', [now(), now()->addDays(self::WINDOW_DAYS)])
            ->selectRaw('COUNT(CASE WHEN '.self::followupDueSql().' THEN 1 END) as followup_due', self::followupDueBindings())
            ->first();

        return array_map('intval', $row);
    }

    /** Lignes de base() avec leur étape. */
    private static function staged(): Builder
    {
        return DB::query()->fromSub(self::base(), 'signups')
            ->select('signups.*')
            ->selectRaw(self::stageSql().' as stage');
    }

    /** Relance prévue aujourd'hui ou en retard, sauf pour un inscrit à ne plus relancer. */
    private static function followupDueSql(): string
    {
        return 'next_followup_at <= ? AND do_not_contact = ?';
    }

    /**
     * @return list<mixed>
     */
    private static function followupDueBindings(): array
    {
        return [now()->toDateString(), false];
    }

    /**
     * Une ligne par inscrit, jointe à sa première entreprise (la plus ancienne), avec les
     * indicateurs dont l'étape est déduite.
     */
    private static function base(): Builder
    {
        $firstBusiness = Business::query()->toBase()->select('businesses.id')
            ->whereColumn('businesses.user_id', 'users.id')
            ->orderBy('businesses.created_at')->orderBy('businesses.id')
            ->limit(1);

        $since = now()->subDays(self::WINDOW_DAYS);

        // Réponses du bot (unités) de la première entreprise sur la fenêtre.
        $botReplies = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->whereColumn('conversations.business_id', 'fb.id')
            ->where('messages.direction', 'outbound')
            ->where('messages.sender_type', 'ai')
            ->where('messages.created_at', '>=', $since)
            ->selectRaw('count(*)');

        $documents = DB::table('documents')->whereColumn('documents.business_id', 'fb.id')->selectRaw('count(*)');
        $products = DB::table('products')->whereColumn('products.business_id', 'fb.id')->selectRaw('count(*)');

        // Formule payante en cours ou en grâce : mêmes règles que BillingService::state().
        $paying = Subscription::query()->current()
            ->whereColumn('subscriptions.business_id', 'fb.id')
            ->where('subscriptions.status', '!=', 'trialing')
            ->whereHas('plan', fn ($plan) => $plan->where('price_fcfa', '>', 0))
            ->toBase()->selectRaw('count(*)');

        $trialEndsAt = DB::table('subscriptions')
            ->whereColumn('subscriptions.business_id', 'fb.id')
            ->where('status', 'trialing')
            ->select('trial_ends_at')
            ->limit(1);

        $latestFollowup = fn (string $column) => DB::table('lead_followups')
            ->whereColumn('lead_followups.user_id', 'users.id')
            ->orderByDesc('lead_followups.created_at')->orderByDesc('lead_followups.id')
            ->select($column)
            ->limit(1);

        return DB::table('users')
            ->leftJoin('businesses as fb', fn ($join) => $join
                ->on('fb.user_id', '=', 'users.id')
                ->on('fb.id', '=', DB::raw('('.$firstBusiness->toSql().')')))
            ->where(fn ($q) => $q->where('users.is_admin', false)->orWhereNull('users.is_admin'))
            ->select([
                'users.id', 'users.name', 'users.email', 'users.phone', 'users.whatsapp_phone',
                'users.city', 'users.country', 'users.created_at', 'users.last_login_at', 'users.do_not_contact',
                'fb.id as business_id', 'fb.name as business_name', 'fb.type as business_type',
                'fb.activity_profile as business_activity_profile',
            ])
            ->selectRaw('CASE WHEN fb.whatsapp_verified = ? AND fb.whatsapp_phone_number_id IS NOT NULL THEN 1 ELSE 0 END as whatsapp_connected', [true])
            ->selectSub($botReplies, 'messages_7d')
            ->selectSub($documents, 'documents_count')
            ->selectSub($products, 'products_count')
            ->selectSub($paying, 'paying_count')
            ->selectSub($trialEndsAt, 'trial_ends_at')
            ->selectSub(DB::table('lead_followups')->whereColumn('lead_followups.user_id', 'users.id')->selectRaw('max(contacted_at)'), 'last_contacted_at')
            ->selectSub($latestFollowup('next_followup_at'), 'next_followup_at')
            // Dernière note écrite (une relance sans note ne l'efface pas).
            ->selectSub($latestFollowup('note')->whereNotNull('note'), 'last_note');
    }

    /** Étape la plus avancée atteinte (colonnes de base()). */
    private static function stageSql(): string
    {
        return "CASE
            WHEN paying_count > 0 THEN 'paying'
            WHEN messages_7d > 0 THEN 'bot_active'
            WHEN whatsapp_connected = 1 THEN 'whatsapp_connected'
            WHEN documents_count > 0 OR products_count > 0 THEN 'info_added'
            WHEN business_id IS NOT NULL THEN 'business_created'
            ELSE 'registered' END";
    }

    /**
     * Nom, e-mail ou téléphone (le numéro aussi sans espaces ni « + »), sans tenir compte des majuscules.
     */
    private static function search(Builder $query, string $search): void
    {
        $pattern = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
        $digits = preg_replace('/\D/', '', $search);
        $like = DB::getDriverName() === 'pgsql' ? 'LIKE ?' : "LIKE ? ESCAPE '\\'";

        $query->where(function (Builder $query) use ($pattern, $digits, $like) {
            foreach (['name', 'email', 'phone', 'whatsapp_phone'] as $column) {
                $query->orWhereRaw("LOWER({$column}) {$like}", [$pattern]);
            }

            if (strlen($digits) >= 3) {
                foreach (['phone', 'whatsapp_phone'] as $column) {
                    $query->orWhereRaw("REPLACE(REPLACE({$column}, ' ', ''), '+', '') LIKE ?", ['%'.$digits.'%']);
                }
            }
        });
    }
}
