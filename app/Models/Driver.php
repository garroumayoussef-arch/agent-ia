<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identité métier du chauffeur VTC — indépendante et volontairement
 * légère, PAS un second système d'identité faisant doublon avec User
 * (qui reste le compte de connexion à l'ERP). user_id est nullable :
 * un chauffeur n'a pas forcément de compte système.
 */
class Driver extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Compte de connexion éventuellement associé à ce chauffeur.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vtcRides(): HasMany
    {
        return $this->hasMany(VtcRide::class);
    }
}
