<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retour d'un gérant (bug, suggestion, question), avec la réponse de l'admin.
 */
class Feedback extends Model
{
    use HasUuids;

    protected $table = 'feedback';

    public const TYPES = ['bug', 'suggestion', 'question', 'autre'];

    public const TYPE_LABELS = ['bug' => 'Bug', 'suggestion' => 'Suggestion', 'question' => 'Question', 'autre' => 'Autre'];

    public const STATUSES = ['nouveau', 'en_cours', 'resolu', 'ferme'];

    public const STATUS_LABELS = ['nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'resolu' => 'Résolu', 'ferme' => 'Fermé'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'nouveau',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'business_id',
        'type',
        'message',
        'page',
        'context',
        'screenshot_path',
        'status',
        'admin_reply',
        'replied_at',
    ];

    /**
     * Le chemin dans le bucket privé n'est pas exposé : la capture se télécharge via l'API.
     *
     * @var list<string>
     */
    protected $hidden = [
        'screenshot_path',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'has_screenshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'replied_at' => 'datetime',
        ];
    }

    public function getHasScreenshotAttribute(): bool
    {
        return ! empty($this->screenshot_path);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
