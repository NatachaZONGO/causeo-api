<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\SubscriptionPlan;
use App\Services\Billing\BillingService;
use App\Services\Billing\BillingState;
use App\Services\Billing\Currency;
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
        ];
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
     * Formule et statut effectifs, calculés à partir des dates.
     */
    public function billingState(): BillingState
    {
        return app(BillingService::class)->state($this);
    }
}
