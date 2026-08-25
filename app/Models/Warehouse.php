<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Étape T10 (fondation multi-entrepôts) — relation vers
 * warehouse_stocks ajoutée en T11a, tenue à jour de façon "live" par
 * StockMovement depuis T11b. Relations vers stock_transfers ajoutées
 * en T12 (transferts de stock entre deux entrepôts).
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

        /*
         * Différé explicitement en T10 ("le garde-fou sera ajouté en
         * T11, quand warehouse_stocks... existeront réellement") —
         * fermé ici, T11a : même principe de protection que Product/
         * ProductVariant/Driver/Vehicle déjà dans ce projet. warehouse_id
         * est en restrictOnDelete() en base (défense en profondeur),
         * cette garde reste le message d'erreur explicite côté
         * application.
         */
        static::deleting(function (self $warehouse): void {
            if ($warehouse->warehouseStocks()->exists()) {
                throw new \Exception(
                    'Impossible de supprimer cet entrepôt : il possède un historique de stock. Désactivez-le plutôt que de le supprimer.'
                );
            }

            /*
             * Étape T12 — même principe, pour l'historique de
             * transferts (en plus de warehouse_stocks ci-dessus).
             * Protection applicative explicite, en plus des
             * contraintes restrictOnDelete() déjà posées sur
             * stock_transfers.from_warehouse_id/to_warehouse_id.
             */
            if ($warehouse->outgoingTransfers()->exists() || $warehouse->incomingTransfers()->exists()) {
                throw new \Exception(
                    'Impossible de supprimer cet entrepôt : il possède un historique de transferts de stock. Désactivez-le plutôt que de le supprimer.'
                );
            }
        });
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    /*
     * =============================================================
     * RELATIONS : TRANSFERTS DE STOCK (T12)
     * =============================================================
     */

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_warehouse_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_warehouse_id');
    }
}
