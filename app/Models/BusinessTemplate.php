<?php

namespace App\Models;

use App\Support\ActivityProfile;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessTemplate extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'icon',
        'description',
        'type',
        'default_greeting',
        'default_ai_instructions',
        'sample_faq',
        'default_modules',
        'is_active',
        'sort_order',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'activity_question',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sample_faq' => 'array',
            'default_modules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Question à poser à l'inscription quand le modèle ne dit pas l'activité (« Autre ») : libellé et
     * options (value à renvoyer dans activity_profile). Null pour les autres modèles.
     *
     * @return Attribute<?array<string, mixed>, never>
     */
    protected function activityQuestion(): Attribute
    {
        return Attribute::get(fn () => ActivityProfile::choiceQuestion($this->type));
    }
}
