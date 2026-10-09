<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Trace d'un essai Pro accordé : un seul par utilisateur et par numéro WhatsApp.
 */
class TrialUsage extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'business_id',
        'whatsapp_number',
    ];
}
