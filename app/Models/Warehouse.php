<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Étape T10 (fondation multi-entrepôts) — modèle volontairement
 * autonome : aucune relation vers Product/ProductVariant/StockMovement
 * pour l'instant (elles viendront en T11 : warehouse_stocks, et T12 :
 * stock_transfers). Ce modèle ne fait encore rien tourner dans
 * l'application — il n'est référencé par rien d'existant.
 */
class Warehouse extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Garantit qu'un seul entrepôt est marqué "par défaut" à la fois —
     * contrainte métier non exprimable proprement par une seule
     * contrainte SQL portable, donc posée ici, au niveau modèle (même
     * principe que les gardes déjà utilisées ailleurs dans ce projet,
     * ex. VtcRide::updating()). Activer is_default sur un entrepôt
     * désactive silencieusement ce flag sur tous les autres — jamais
     * l'inverse (retirer le défaut d'un entrepôt n'en désigne aucun
     * autre automatiquement).
     */
    protected static function booted(): void
    {
        static::saving(function (self $warehouse): void {
            if (! $warehouse->is_default || ! $warehouse->isDirty('is_default')) {
                return;
            }

            // $warehouse->exists est false pour une création (saving()
            // se déclenche AVANT creating() : $warehouse->id est encore
            // null à cet instant, l'exclure via ->id échouerait
            // silencieusement — WHERE id != NULL n'est jamais vrai).
            $query = static::where('is_default', true);

            if ($warehouse->exists) {
                $query->where('id', '!=', $warehouse->id);
            }

            $query->update(['is_default' => false]);
        });
    }
}
