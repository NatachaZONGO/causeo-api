<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\LeadFollowup;
use App\Models\User;
use App\Services\Admin\SignupListQuery;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Suivi des inscrits par le super admin : où en est chaque inscrit (étape, usage, formule)
 * et ses relances. Aucune donnée d'inscrit n'est écrite dans les logs.
 */
class AdminSignupController extends Controller
{
    /** Colonnes de l'export CSV, dans l'ordre (clés de row()). */
    private const CSV_COLUMNS = [
        'name' => 'Nom',
        'email' => 'E-mail',
        'phone' => 'Téléphone',
        'whatsapp_phone' => 'WhatsApp',
        'city' => 'Ville',
        'country' => 'Pays',
        'created_at' => 'Inscription',
        'last_login_at' => 'Dernière connexion',
        'business.name' => 'Entreprise',
        'business.type' => 'Type',
        'business.activity_profile' => 'Activité',
        'stage' => 'Étape',
        'billing.plan' => 'Formule',
        'billing.trial_ends_at' => 'Fin d\'essai',
        'messages_7d' => 'Messages traités (7 j)',
        'last_contacted_at' => 'Dernière relance',
        'next_followup_at' => 'Prochaine relance',
        'last_note' => 'Dernière note',
        'do_not_contact' => 'Ne plus relancer',
    ];

    /**
     * Page d'inscrits filtrée, et counts : les compteurs de tous les inscrits (sans filtre).
     */
    public function index(Request $request, BillingService $billing): JsonResponse
    {
        $signups = SignupListQuery::build($this->filters($request))->paginate(20);

        $signups->setCollection($this->rows($signups->getCollection(), $billing));

        return response()->json($signups->toArray() + ['counts' => SignupListQuery::counts()]);
    }

    /**
     * Ne plus relancer un inscrit (ou de nouveau le relancer) : il sort des relances dues.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'do_not_contact' => ['required', 'boolean'],
        ]);

        abort_if($user->is_admin, 404);

        $user->forceFill(['do_not_contact' => $data['do_not_contact']])->save();

        return response()->json([
            'message' => $user->do_not_contact ? 'Cet inscrit ne sera plus relancé.' : 'Cet inscrit peut de nouveau être relancé.',
            'do_not_contact' => $user->do_not_contact,
        ]);
    }

    /**
     * Mêmes colonnes et filtres que la liste, en CSV (point-virgule et BOM UTF-8 pour Excel).
     */
    public function export(Request $request, BillingService $billing): StreamedResponse
    {
        $query = SignupListQuery::build($this->filters($request));

        return response()->streamDownload(function () use ($query, $billing) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values(self::CSV_COLUMNS), ';');

            $query->chunk(500, function (Collection $chunk) use ($out, $billing) {
                foreach ($this->rows($chunk, $billing) as $row) {
                    fputcsv($out, array_map(
                        fn (string $key) => $this->csvValue(match ($key) {
                            'billing.plan' => data_get($row, 'billing.plan.name'),
                            'do_not_contact' => $row['do_not_contact'] ? 'Oui' : 'Non',
                            default => data_get($row, $key),
                        }),
                        array_keys(self::CSV_COLUMNS),
                    ), ';');
                }
            });

            fclose($out);
        }, 'inscrits-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * Historique des relances d'un inscrit, la plus récente d'abord.
     */
    public function followups(User $user): JsonResponse
    {
        return response()->json([
            'data' => $user->leadFollowups()
                ->with('author:id,name')
                ->latest()->orderByDesc('id')
                ->get(),
        ]);
    }

    public function storeFollowup(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
            'contacted_at' => ['nullable', 'date'],
            'next_followup_at' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'next_followup_at.date_format' => 'La prochaine relance doit être une date (AAAA-MM-JJ).',
        ]);

        $followup = $user->leadFollowups()->create($data + ['created_by' => $request->user()->id]);

        return response()->json([
            'message' => 'La relance a été enregistrée.',
            'followup' => $followup->load('author:id,name'),
        ], 201);
    }

    /**
     * @return array{stage: ?string, search: ?string, trial_ending: bool, followup_due: bool, from: ?string, to: ?string}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'stage' => ['nullable', Rule::in(SignupListQuery::STAGES)],
            'search' => ['nullable', 'string', 'max:100'],
            'trial_ending' => ['nullable', 'boolean'],
            'followup_due' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'stage.in' => 'Étape inconnue.',
        ]);

        return [
            'stage' => $data['stage'] ?? null,
            'search' => $data['search'] ?? null,
            'trial_ending' => $request->boolean('trial_ending'),
            'followup_due' => $request->boolean('followup_due'),
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ];
    }

    /**
     * Lignes de la liste : la formule effective vient de billingState(), pour toutes les
     * entreprises de la page en une seule requête (abonnements et formules préchargés).
     *
     * @param  Collection<int, object>  $signups
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(Collection $signups, BillingService $billing): Collection
    {
        $businesses = Business::query()
            ->with('subscription.plan')
            ->findMany($signups->pluck('business_id')->filter()->all())
            ->keyBy('id');

        return $signups->map(function (object $signup) use ($businesses, $billing) {
            $business = $businesses->get($signup->business_id);
            $state = $business ? $billing->state($business) : null;

            return [
                'id' => $signup->id,
                'name' => $signup->name,
                'email' => $signup->email,
                'phone' => $signup->phone,
                'whatsapp_phone' => $signup->whatsapp_phone,
                'city' => $signup->city,
                'country' => $signup->country,
                'created_at' => $this->iso($signup->created_at),
                'last_login_at' => $this->iso($signup->last_login_at),
                'business' => $business ? [
                    'id' => $business->id,
                    'name' => $business->name,
                    'type' => $signup->business_type,
                    'activity_profile' => $business->activityProfile(),
                ] : null,
                'stage' => $signup->stage,
                'billing' => $state ? [
                    'status' => $state->status,
                    'plan' => ['slug' => $state->plan->slug, 'name' => $state->plan->name],
                    'trial_ends_at' => $state->isTrial() ? $state->endsAt?->toIso8601String() : null,
                    'days_left' => $state->daysLeft(),
                ] : null,
                'messages_7d' => (int) $signup->messages_7d,
                'last_contacted_at' => $this->iso($signup->last_contacted_at),
                'next_followup_at' => $signup->next_followup_at ? Carbon::parse($signup->next_followup_at)->toDateString() : null,
                'last_note' => $signup->last_note,
                'do_not_contact' => (bool) $signup->do_not_contact,
            ];
        });
    }

    private function iso(?string $value): ?string
    {
        return $value !== null ? Carbon::parse($value)->toIso8601String() : null;
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) $value;

        // Pas de formule interprétée par le tableur (=, +, -, @ en tête de cellule), sauf un numéro « +226 … ».
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && preg_match('/^\+[\d ]+$/', $value) !== 1 ? "'".$value : $value;
    }
}
