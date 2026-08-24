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

    /**
     * Étape 5.11 : vtc_rides.driver_id est en nullOnDelete — supprimer
     * ce chauffeur ne détruirait aucune course, mais lui ferait perdre
     * la trace de qui l'a réellement effectuée, y compris pour une
     * course déjà confirmée (historique figé, cf. VtcRide::updating()).
     * Même principe que ProductVariant::deleting() (FK nullOnDelete,
     * même risque de perte de traçabilité) : bloque dès qu'UNE
     * VtcRide existe, brouillon compris — décision explicite, pas
     * seulement les courses confirmées, pour rester cohérent avec ce
     * précédent plutôt que d'introduire une règle différente ici.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $driver): void {
            if ($driver->vtcRides()->exists()) {
                throw new \Exception(
                    'Impossible de supprimer ce chauffeur : il est référencé par au moins une course VTC. Désactivez-le plutôt que de le supprimer.'
                );
            }
        });
    }
}
