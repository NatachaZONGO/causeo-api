<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\SubscriptionPlan;
use App\Services\Billing\BillingService;
use App\Services\Billing\BillingState;
use App\Services\Billing\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Business extends Model
{
    use HasFactory, HasUuids;

    /** Modules activables par entreprise. */
    public const MODULES = ['orders', 'appointments'];

    /** Réglages « Style de réponse » du gérant et leurs valeurs possibles (null : pas de choix). */
    public const REPLY_STYLE_OPTIONS = [
        'address' => ['tu', 'vous'],
        'emojis' => ['never', 'sometimes', 'often'],
        'length' => ['very_short', 'short'],
    ];

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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'whatsapp_token',
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
     * @return array{address: ?string, emojis: ?string, length: ?string, signature: ?string, learn_from_owner: bool}
     */
    public static function normalizeReplyStyle(array $style): array
    {
        $option = fn (string $key) => in_array($style[$key] ?? null, self::REPLY_STYLE_OPTIONS[$key], true) ? $style[$key] : null;
        $signature = trim((string) ($style['signature'] ?? ''));

        return [
            'address' => $option('address'),
            'emojis' => $option('emojis'),
            'length' => $option('length'),
            'signature' => $signature !== '' ? $signature : null,
            'learn_from_owner' => (bool) ($style['learn_from_owner'] ?? true),
        ];
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
     * Modules par défaut d'un type d'entreprise, repris du template de même type.
     *
     * @return list<string>
     */
    public static function defaultModulesFor(?string $type): array
    {
        if ($type === null) {
            return [];
        }

        return BusinessTemplate::query()->where('type', $type)->value('default_modules') ?? [];
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
