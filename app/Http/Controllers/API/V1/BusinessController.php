<?php

namespace App\Http\Controllers\API\V1;

use App\Enums\BusinessType;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Billing\BillingService;
use App\Support\ActivityProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    /**
     * Lister les entreprises de l'utilisateur connecté.
     */
    public function index(): JsonResponse
    {
        $businesses = auth()->user()
            ->businesses()
            ->latest()
            ->paginate(10);

        return response()->json($businesses);
    }

    /**
     * Créer une entreprise pour l'utilisateur connecté.
     */
    public function store(Request $request, BillingService $billing): JsonResponse
    {
        $data = $this->normalizeInput($request->validate($this->rules(), $this->messages()));

        $data['country'] = $data['country'] ?? 'BF';
        $data['modules'] = array_key_exists('modules', $data)
            ? $this->normalizeModules($data['modules'])
            : Business::defaultModulesFor($data['type']);
        $data['activity_profile'] ??= ActivityProfile::forType($data['type']);

        $business = auth()->user()->businesses()->create($data);

        // Essai Pro (TRIAL_DAYS jours ou TRIAL_UNITS unités), sauf si l'utilisateur a déjà eu un essai.
        $billing->startTrial($business, auth()->user());

        return response()->json([
            'message' => 'L\'entreprise a été créée avec succès.',
            'business' => $business->fresh(),
        ], 201);
    }

    /**
     * Afficher une entreprise avec ses statistiques.
     */
    public function show(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $business->loadCount([
            'documents',
            'conversations',
            'managerReplies',
            'escalations as pending_escalations_count' => fn ($query) => $query->where('status', 'pending'),
        ]);

        return response()->json([
            'business' => $business,
        ]);
    }

    /**
     * Mettre à jour une entreprise.
     */
    public function update(Request $request, Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $data = $this->normalizeInput($request->validate($this->rules(nullable: true), $this->messages()));

        if (array_key_exists('modules', $data)) {
            $data['modules'] = $this->normalizeModules($data['modules']);
        }

        // Mise à jour partielle : les réglages absents de la requête sont gardés.
        if (array_key_exists('reply_style', $data)) {
            $data['reply_style'] = array_merge($business->reply_style, (array) $data['reply_style']);
        }

        $business->update($data);

        return response()->json([
            'message' => 'L\'entreprise a été mise à jour avec succès.',
            'business' => $business,
        ]);
    }

    /**
     * Supprimer une entreprise.
     */
    public function destroy(Business $business): JsonResponse
    {
        $this->checkOwnership($business);

        $business->delete();

        return response()->json([
            'message' => 'L\'entreprise a été supprimée avec succès.',
        ]);
    }

    /**
     * Couleur de marque en majuscules. « tone » (vous | tu) est enregistré dans
     * reply_style.address, où null vaut « vous ».
     * Il prime sur un reply_style.address envoyé dans la même requête.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeInput(array $data): array
    {
        if (isset($data['brand_color'])) {
            $data['brand_color'] = strtoupper($data['brand_color']);
        }

        if (array_key_exists('tone', $data)) {
            $data['reply_style'] = array_merge((array) ($data['reply_style'] ?? []), ['address' => $data['tone']]);
            unset($data['tone']);
        }

        return $data;
    }

    /**
     * Interrompre la requête si l'entreprise n'appartient pas à l'utilisateur connecté.
     */
    private function checkOwnership(Business $business): void
    {
        abort_if($business->user_id !== auth()->id(), 403, 'Cette entreprise ne vous appartient pas.');
    }

    /**
     * Règles de validation partagées entre store() et update().
     *
     * @return array<string, mixed>
     */
    private function rules(bool $nullable = false): array
    {
        $required = $nullable ? 'nullable' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(BusinessType::class)],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'custom_greeting' => ['nullable', 'string'],
            'ai_instructions' => ['nullable', 'string'],
            'delivery_enabled' => ['sometimes', 'boolean'],
            'pickup_enabled' => ['sometimes', 'boolean'],
            'shipping_enabled' => ['sometimes', 'boolean'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(Business::MODULES)],
            // Tutoiement ou vouvoiement des clients (« vous » par défaut).
            'tone' => ['sometimes', Rule::in(Business::TONES)],
            // « Style de réponse » : null pour un réglage revient au profil appris (sauf address : « vous »).
            'reply_style' => ['sometimes', 'array:address,emojis,length,signature,learn_from_owner'],
            'reply_style.address' => ['nullable', Rule::in(Business::REPLY_STYLE_OPTIONS['address'])],
            'reply_style.emojis' => ['nullable', Rule::in(Business::REPLY_STYLE_OPTIONS['emojis'])],
            'reply_style.length' => ['nullable', Rule::in(Business::REPLY_STYLE_OPTIONS['length'])],
            'reply_style.signature' => ['nullable', 'string', 'max:60'],
            'reply_style.learn_from_owner' => ['sometimes', 'boolean'],
            // Réponse au client quand son vocal n'est pas compris, ou pour un document ou une vidéo (désactivée par défaut).
            'voice_fallback_reply' => ['sometimes', 'boolean'],
            'voice_fallback_message' => ['nullable', 'string', 'max:300'],
            // Couleur de marque (#RRGGBB) ; le texte à poser dessus est calculé (brand_text_color).
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // Catalogue : e-mail en plus de la cloche quand un article passe en stock faible ou en rupture.
            'stock_alert_email' => ['sometimes', 'boolean'],
            // Pause de l'assistant quand le gérant écrit à un client : 1, 6 ou 24 h, ou 0 (jusqu'à réactivation).
            'ai_pause_hours' => ['sometimes', 'integer', Rule::in(Business::AI_PAUSE_HOURS)],
            // Profil d'activité : vocabulaire et réglages du catalogue (Catalogue, Menu, Prestations).
            'activity_profile' => ['sometimes', Rule::in(ActivityProfile::AVAILABLE)],
            // Restaurant : les plats épuisés redeviennent disponibles chaque matin (sinon à la main).
            'catalog_daily_reset' => ['sometimes', 'boolean'],
            'dine_in_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Dédoublonner la liste des modules en gardant l'ordre de Business::MODULES.
     *
     * @param  array<int, string>|null  $modules
     * @return list<string>
     */
    private function normalizeModules(?array $modules): array
    {
        return array_values(array_intersect(Business::MODULES, $modules ?? []));
    }

    /**
     * Messages d'erreur en français.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'Le nom de l\'entreprise est obligatoire.',
            'name.string' => 'Le nom doit être une chaîne de caractères.',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères.',
            'type.required' => 'Le type d\'entreprise est obligatoire.',
            'type.enum' => 'Le type d\'entreprise sélectionné n\'est pas valide.',
            'description.string' => 'La description doit être une chaîne de caractères.',
            'phone.max' => 'Le téléphone ne doit pas dépasser 20 caractères.',
            'address.max' => 'L\'adresse ne doit pas dépasser 255 caractères.',
            'city.max' => 'La ville ne doit pas dépasser 100 caractères.',
            'country.size' => 'Le pays doit être un code à 2 lettres.',
            'website.url' => 'Le site web doit être une adresse valide commençant par http:// ou https://.',
            'website.max' => 'Le site web ne doit pas dépasser 255 caractères.',
            'custom_greeting.string' => 'Le message d\'accueil doit être une chaîne de caractères.',
            'ai_instructions.string' => 'Les instructions IA doivent être une chaîne de caractères.',
            'delivery_enabled.boolean' => 'La livraison doit être activée ou désactivée (vrai ou faux).',
            'pickup_enabled.boolean' => 'Le retrait en boutique doit être activé ou désactivé (vrai ou faux).',
            'shipping_enabled.boolean' => 'L\'expédition doit être activée ou désactivée (vrai ou faux).',
            'modules.array' => 'Les modules doivent être une liste.',
            'modules.*.string' => 'Chaque module doit être une chaîne de caractères.',
            'modules.*.in' => 'Module inconnu. Modules disponibles : '.implode(', ', Business::MODULES).'.',
            'tone.in' => 'Le ton doit être « vous » ou « tu ».',
            'reply_style.array' => 'Le style de réponse doit contenir address, emojis, length, signature ou learn_from_owner.',
            'reply_style.address.in' => 'Choisissez « tu » ou « vous ».',
            'reply_style.emojis.in' => 'Emojis : never, sometimes ou often.',
            'reply_style.length.in' => 'Longueur : very_short ou short.',
            'reply_style.signature.string' => 'La signature doit être un texte.',
            'reply_style.signature.max' => 'La signature ne doit pas dépasser 60 caractères.',
            'voice_fallback_reply.boolean' => 'La réponse aux vocaux non compris, documents et vidéos doit être activée ou désactivée (vrai ou faux).',
            'voice_fallback_message.string' => 'Le message de réponse aux vocaux, documents et vidéos doit être un texte.',
            'voice_fallback_message.max' => 'Le message de réponse aux vocaux, documents et vidéos ne doit pas dépasser 300 caractères.',
            'activity_profile.in' => 'Activité inconnue. Activités disponibles : '.implode(', ', ActivityProfile::AVAILABLE).'.',
            'catalog_daily_reset.boolean' => 'La remise à disponible chaque matin doit être activée ou désactivée (vrai ou faux).',
            'dine_in_enabled.boolean' => 'La consommation sur place doit être activée ou désactivée (vrai ou faux).',
            'ai_pause_hours.in' => 'La pause de l\'assistant dure 1, 6 ou 24 heures, ou jusqu\'à réactivation (0).',
            'stock_alert_email.boolean' => 'Les alertes de stock par e-mail doivent être activées ou désactivées (vrai ou faux).',
            'brand_color.regex' => 'La couleur doit être au format hexadécimal #RRGGBB, par exemple #0F766E.',
            'brand_color.string' => 'La couleur doit être au format hexadécimal #RRGGBB, par exemple #0F766E.',
            'reply_style.learn_from_owner.boolean' => '« Apprendre de ma façon d\'écrire » doit être activé ou désactivé (vrai ou faux).',
        ];
    }
}
