<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\SubscriptionPlan;
use App\Services\Billing\BillingService;
use App\Services\Billing\BillingState;
use App\Services\Billing\Currency;
use App\Support\ActivityProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Business extends Model
{
    use HasFactory, HasUuids;

    /** Modules activables par entreprise. */
    public const MODULES = ['orders', 'appointments', 'catalog'];

    /** Tutoiement ou vouvoiement des clients (Mon entreprise) : « vous » sauf choix contraire du gérant. */
    public const TONES = ['vous', 'tu'];

    /** Disque des logos (bucket media de Supabase, public). */
    public const LOGO_DISK = 'supabase_media';

    /** Texte foncé posé sur une couleur de marque claire. */
    public const DARK_TEXT = '#111827';

    /** Fuseau horaire des entreprises (pas de colonne dédiée pour l'instant). */
    public const DEFAULT_TIMEZONE = 'Africa/Ouagadougou';

    /** Réglages « Style de réponse » du gérant et leurs valeurs possibles (null : pas de choix). */
    public const REPLY_STYLE_OPTIONS = [
        'address' => ['tu', 'vous'],
        'emojis' => ['never', 'sometimes', 'often'],
        'length' => ['very_short', 'short'],
    ];

    /**
     * Durées possibles (heures) de la pause de l'assistant quand le gérant écrit à un client ;
     * 0 (MANUAL_AI_PAUSE) : jusqu'à réactivation manuelle.
     */
    public const AI_PAUSE_HOURS = [self::MANUAL_AI_PAUSE, 1, 6, 24];

    public const MANUAL_AI_PAUSE = 0;

    public const DEFAULT_AI_PAUSE_HOURS = 6;

    /**
     * Valeurs par défaut avant enregistrement : aucun module tant qu'on ne l'a pas choisi.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'modules' => '[]',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'type',
        'description',
        'phone',
        'whatsapp_phone_number_id',
        'whatsapp_waba_id',
        'whatsapp_token',
        'whatsapp_verified',
        'whatsapp_connected_at',
        'whatsapp_display_name',
        'address',
        'city',
        'country',
        'logo_url',
        'website',
        'opening_hours',
        'custom_greeting',
        'ai_instructions',
        'plan',
        'is_active',
        'delivery_enabled',
        'pickup_enabled',
        'shipping_enabled',
        'modules',
        'reply_style',
        'voice_fallback_reply',
        'voice_fallback_message',
        'brand_color',
        'logo_path',
        'stock_alert_email',
        'ai_pause_hours',
        'activity_profile',
        'catalog_daily_reset',
        'dine_in_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'whatsapp_token',
        // Chemin interne dans le bucket : seule l'URL publique (logo_url) est renvoyée.
        'logo_path',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'tone',
        'logo_url',
        'brand_text_color',
        'activity',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BusinessType::class,
            'plan' => SubscriptionPlan::class,
            'opening_hours' => 'array',
            'is_active' => 'boolean',
            'whatsapp_verified' => 'boolean',
            'whatsapp_connected_at' => 'datetime',
            // Chiffré en base avec APP_KEY : ne jamais lire la colonne en SQL brut.
            'whatsapp_token' => 'encrypted',
            'delivery_enabled' => 'boolean',
            'pickup_enabled' => 'boolean',
            'shipping_enabled' => 'boolean',
            'modules' => 'array',
            'style_profile' => 'array',
            'style_profile_updated_at' => 'datetime',
            'voice_fallback_reply' => 'boolean',
            'stock_alert_email' => 'boolean',
            'ai_pause_hours' => 'integer',
            'catalog_daily_reset' => 'boolean',
            'dine_in_enabled' => 'boolean',
            'contacts_exported_at' => 'datetime',
        ];
    }

    /**
     * Réglages « Style de réponse », toujours complets : address, emojis, length et
     * signature (null si le gérant n'a rien choisi), learn_from_owner (vrai par défaut).
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>|null>
     */
    protected function replyStyle(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => self::normalizeReplyStyle(is_string($value) ? (array) json_decode($value, true) : []),
            set: fn (?array $value) => json_encode(self::normalizeReplyStyle($value ?? []), JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array{address: ?string, emojis: string, length: ?string, signature: ?string, learn_from_owner: bool}
     */
    public static function normalizeReplyStyle(array $style): array
    {
        $option = fn (string $key) => in_array($style[$key] ?? null, self::REPLY_STYLE_OPTIONS[$key], true) ? $style[$key] : null;
        $signature = trim((string) ($style['signature'] ?? ''));

        return [
            'address' => $option('address'),
            // Sans choix du gérant : pas d'emoji (voir App\Support\EmojiStyle).
            'emojis' => $option('emojis') ?? 'never',
            'length' => $option('length'),
            'signature' => $signature !== '' ? $signature : null,
            'learn_from_owner' => (bool) ($style['learn_from_owner'] ?? true),
        ];
    }

    /**
     * Tutoiement ou vouvoiement imposé au bot : « tu » seulement si le gérant l'a choisi
     * (reply_style.address), « vous » sinon, quel que soit le profil appris ou le client.
     *
     * @return Attribute<string, never>
     */
    protected function tone(): Attribute
    {
        return Attribute::get(fn () => $this->reply_style['address'] === 'tu' ? 'tu' : 'vous');
    }

    /**
     * Profil d'activité (boutique, restaurant, services) : celui choisi par le gérant, sinon celui
     * du modèle choisi à l'inscription.
     */
    public function activityProfile(): string
    {
        $profile = $this->getAttribute('activity_profile');
        $type = $this->type instanceof \BackedEnum ? $this->type->value : $this->type;

        return in_array($profile, ActivityProfile::AVAILABLE, true) ? $profile : ActivityProfile::forType($type);
    }

    /**
     * Vocabulaire et réglages du profil, pour les libellés du dashboard (voir ActivityProfile).
     *
     * @return Attribute<array<string, mixed>, never>
     */
    protected function activity(): Attribute
    {
        return Attribute::get(fn () => ActivityProfile::definition($this->activityProfile()));
    }

    /**
     * URL publique du logo téléversé (bucket media) ; à défaut, l'ancienne colonne
     * logo_url (adresse externe saisie à la main), sinon null.
     *
     * @return Attribute<?string, never>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => ! empty($this->getAttribute('logo_path'))
            ? Storage::disk(self::LOGO_DISK)->url($this->getAttribute('logo_path'))
            : ($value ?: null));
    }

    /**
     * Couleur du texte posé sur brand_color, la plus lisible des deux (contraste WCAG) :
     * blanc ou gris très foncé. Null sans couleur de marque.
     *
     * @return Attribute<?string, never>
     */
    protected function brandTextColor(): Attribute
    {
        return Attribute::get(function (): ?string {
            $color = $this->getAttribute('brand_color');

            if (! is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
                return null;
            }

            $luminance = self::relativeLuminance($color);

            // Contraste avec le blanc (luminance 1) et avec #111827.
            $withWhite = 1.05 / ($luminance + 0.05);
            $withDark = ($luminance + 0.05) / (self::relativeLuminance(self::DARK_TEXT) + 0.05);

            return $withWhite >= $withDark ? '#FFFFFF' : self::DARK_TEXT;
        });
    }

    /** Luminance relative d'une couleur #RRGGBB (WCAG 2.x). */
    private static function relativeLuminance(string $hex): float
    {
        $channels = array_map(function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Fuseau horaire de l'entreprise : celui de l'attribut timezone s'il est valide,
     * sinon Africa/Ouagadougou (GMT+0).
     */
    public function localTimezone(): string
    {
        $timezone = is_string($this->getAttribute('timezone')) ? trim($this->getAttribute('timezone')) : '';

        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : self::DEFAULT_TIMEZONE;
    }

    /** Le bot peut-il apprendre de la façon d'écrire du gérant (profil et exemples) ? */
    public function learnsFromOwner(): bool
    {
        return $this->reply_style['learn_from_owner'];
    }

    /**
     * Le gérant a-t-il activé ce module (voir MODULES) ? Ses pages restent alors
     * consultables, même si la formule ne permet plus de l'utiliser.
     */
    public function moduleEnabled(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }

    /**
     * Le module est-il utilisable : activé par le gérant ET compris dans la formule
     * effective (le Gratuit n'en comprend aucun) ?
     */
    public function hasModule(string $module): bool
    {
        return $this->moduleEnabled($module) && $this->billingState()->plan->allowsModule($module);
    }

    /**
     * Modules par défaut d'un type d'entreprise, repris du template de même type ; le catalogue
     * (Catalogue, Menu ou Prestations selon l'activité) l'est toujours.
     *
     * @param  list<string>|null  $templateModules  modules du modèle déjà chargé
     * @return list<string>
     */
    public static function defaultModulesFor(?string $type, ?array $templateModules = null): array
    {
        $modules = $templateModules ?? ($type === null ? [] : (BusinessTemplate::query()->where('type', $type)->value('default_modules') ?? []));

        return array_values(array_unique([...$modules, 'catalog']));
    }

    /**
     * Modes de remise des commandes activés (delivery, pickup, shipping).
     *
     * @return list<string>
     */
    public function enabledFulfillmentTypes(): array
    {
        return array_values(array_filter(
            Order::FULFILLMENT_TYPES,
            fn (string $type) => (bool) $this->getAttribute("{$type}_enabled"),
        ));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Document>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<BusinessMedia>
     */
    public function media(): HasMany
    {
        return $this->hasMany(BusinessMedia::class);
    }

    /**
     * @return HasMany<Conversation>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<Escalation>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class);
    }

    /**
     * Notifications destinées au gérant (celles destinées aux admins Causeo sont exclues).
     *
     * @return HasMany<Notification>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->where('audience', Notification::OWNER);
    }

    /**
     * @return HasMany<Order>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Appointment>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Articles du catalogue (module « catalog »).
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Statuts WhatsApp enregistrés par le gérant (statuts du jour).
     *
     * @return HasMany<StatusPost, $this>
     */
    public function statusPosts(): HasMany
    {
        return $this->hasMany(StatusPost::class);
    }

    /**
     * @return HasMany<LearnedResponse>
     */
    public function learnedResponses(): HasMany
    {
        return $this->hasMany(LearnedResponse::class);
    }

    /**
     * @return HasMany<ManagerReply, $this>
     */
    public function managerReplies(): HasMany
    {
        return $this->hasMany(ManagerReply::class);
    }

    /**
     * Abonnement du business (essai, formule payante ou Interne), absent en Gratuit.
     *
     * @return HasOne<Subscription>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Devise d'affichage des prix, selon le pays (XOF, EUR ou USD).
     */
    public function currency(): string
    {
        return Currency::forCountry($this->country);
    }

    /**
     * Businesses dont la formule EFFECTIVE (calculée à partir des dates) est $slug, et non
     * la colonne businesses.plan, qui n'est resynchronisée que toutes les heures.
     *
     * @param  Builder<Business>  $query
     * @return Builder<Business>
     */
    public function scopeWhereEffectivePlan(Builder $query, string $slug): Builder
    {
        $onPlan = fn (string $operator) => fn (Builder $subscription) => $subscription
            ->current()
            ->whereHas('plan', fn (Builder $plan) => $plan->where('slug', $operator, $slug));

        // Gratuit : aucun abonnement en cours sur une autre formule (essai échu, grâce dépassée, pas d'abonnement…).
        return $slug === Plan::FREE
            ? $query->whereDoesntHave('subscription', $onPlan('!='))
            : $query->whereHas('subscription', $onPlan('='));
    }

    /**
     * Formule et statut effectifs, calculés à partir des dates.
     */
    public function billingState(): BillingState
    {
        return app(BillingService::class)->state($this);
    }
}
