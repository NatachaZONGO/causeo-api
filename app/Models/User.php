<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'whatsapp_phone',
        'city',
        'country',
        'google_id',
        'is_admin',
        'notification_phone',
        'whatsapp_alerts_enabled',
        'whatsapp_alerts_consented_at',
        'whatsapp_alerts_consent_version',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'whatsapp_alerts_enabled' => 'boolean',
            'whatsapp_alerts_consented_at' => 'datetime',
            'last_login_at' => 'datetime',
            'do_not_contact' => 'boolean',
        ];
    }

    /**
     * Lien de réinitialisation vers le dashboard, en français.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Noter la connexion (e-mail ou Google) sans toucher updated_at.
     */
    public function recordLogin(): void
    {
        $this->forceFill(['last_login_at' => now()]);
        static::query()->whereKey($this->getKey())->toBase()->update(['last_login_at' => $this->last_login_at]);
        $this->syncOriginalAttribute('last_login_at');
    }

    /**
     * Get the businesses that belong to the user.
     *
     * @return HasMany<Business>
     */
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /**
     * Relances par le super admin (suivi des inscrits).
     *
     * @return HasMany<LeadFollowup, $this>
     */
    public function leadFollowups(): HasMany
    {
        return $this->hasMany(LeadFollowup::class);
    }
}
