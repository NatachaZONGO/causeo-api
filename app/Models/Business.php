<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\SubscriptionPlan;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasFactory, HasUuids;

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
        'opening_hours',
        'custom_greeting',
        'ai_instructions',
        'plan',
        'is_active',
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
        ];
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
     * @return HasMany<LearnedResponse>
     */
    public function learnedResponses(): HasMany
    {
        return $this->hasMany(LearnedResponse::class);
    }

    /**
     * @return HasMany<Subscription>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Determine whether the business has reached its monthly message limit
     * for its current plan.
     */
    public function hasReachedMessageLimit(): bool
    {
        $limit = match ($this->plan) {
            SubscriptionPlan::Free => 50,
            SubscriptionPlan::Starter => 500,
            SubscriptionPlan::Pro => 2000,
            SubscriptionPlan::Enterprise => null,
            default => 50,
        };

        if ($limit === null) {
            return false;
        }

        return (int) $this->monthly_message_count >= $limit;
    }
}
