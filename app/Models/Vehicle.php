<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function vtcRides(): HasMany
    {
        return $this->hasMany(VtcRide::class);
    }

    /**
     * Étape 5.11 : même protection que Driver::deleting() (cf. son
     * commentaire détaillé) — vtc_rides.vehicle_id est en nullOnDelete,
     * donc supprimer ce véhicule lui ferait perdre la trace de son
     * implication dans une course, y compris déjà confirmée. Bloque dès
     * qu'UNE VtcRide existe, brouillon compris.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $vehicle): void {
            if ($vehicle->vtcRides()->exists()) {
                throw new \Exception(
                    'Impossible de supprimer ce véhicule : il est référencé par au moins une course VTC. Désactivez-le plutôt que de le supprimer.'
                );
            }
        });
    }
}
