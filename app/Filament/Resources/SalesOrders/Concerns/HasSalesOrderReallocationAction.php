<?php

namespace App\Filament\Resources\SalesOrders\Concerns;

use App\Models\PurchaseOrderItemReturn;
use App\Models\SalesOrder;
use App\Models\SalesOrderItemAllocation;

trait HasSalesOrderReallocationAction
{
    /**
     * Chantier Dropshipping, étape D2.9 (spécification validée) —
     * orchestration EXPLICITE de la ré-allocation pour une SalesOrder :
     * parcourt les lignes, calcule l'éligibilité (retour intégral au
     * fournisseur initial) sur des données fraîches, et délègue toute
     * décision à SalesOrderItemAllocation::reallocateFor() — aucune
     * règle de sélection fournisseur ni de calcul de retour dupliquée
     * ici, celles-ci restent intégralement dans SupplierSourcingResolver
     * et PurchaseOrderItemReturn::totalReturnedFor().
     *
     * $item->allocation (hasOne()->latestOfMany(), D2.9) retourne
     * toujours l'allocation ACTIVE de la ligne, jamais une allocation
     * historique déjà remplacée — relire cette relation à chaque appel
     * (jamais mise en cache entre deux invocations de cette méthode)
     * garantit qu'un second déclenchement de l'action, après une
     * ré-allocation réussie, voit la NOUVELLE allocation (sans
     * PurchaseOrderItem générée pour elle) : elle retombe naturellement
     * dans $skipped, jamais une double ré-allocation.
     *
     * Catégorisation à trois voies (jamais une simple paire succès/échec
     * fusionnée) :
     * - skipped : ligne sans allocation, ou allocation non éligible
     *   (aucune commande fournisseur générée, ou retour non intégral) —
     *   ce n'est pas une erreur, c'est l'état normal de la majorité des
     *   lignes à tout instant.
     * - reallocated : ré-allocation réussie.
     * - failed : exception levée par reallocateFor() malgré
     *   l'éligibilité apparente (aucun fournisseur alternatif
     *   disponible, ou concurrence détectée) — message métier explicite
     *   conservé tel quel (jamais reformulé), pour rester une
     *   information explicite pour l'utilisateur (spécification
     *   validée), pas un simple compte.
     *
     * Traitement séquentiel, sans transaction englobante : chaque
     * reallocateFor() gère déjà sa propre transaction/verrouillage
     * (D2.9). Une exception sur une ligne est capturée individuellement
     * et n'interrompt jamais le traitement des lignes suivantes — même
     * discipline que orchestrateSourcing() (D2.5.2).
     *
     * Ne modifie ni le statut de la SalesOrder, ni aucune autre donnée
     * que celles écrites par reallocateFor() lui-même. Aucune notion
     * d'autorisation/visibilité ici : responsabilité de l'action
     * Filament elle-même.
     *
     * @return array{reallocated: int[], skipped: int[], failed: array<int, string>}
     */
    protected function orchestrateReallocation(SalesOrder $record): array
    {
        $reallocated = [];
        $skipped = [];
        $failed = [];

        foreach ($record->items as $item) {
            $current = $item->allocation;

            if ($current === null) {
                $skipped[] = $item->id;

                continue;
            }

            $purchaseOrderItem = $current->purchaseOrderItem;
            $received = $purchaseOrderItem !== null ? (int) $purchaseOrderItem->quantity_received : 0;
            $returned = $purchaseOrderItem !== null
                ? PurchaseOrderItemReturn::totalReturnedFor($purchaseOrderItem)
                : 0;

            $eligible = $purchaseOrderItem !== null && $received > 0 && $returned === $received;

            if (! $eligible) {
                $skipped[] = $item->id;

                continue;
            }

            try {
                SalesOrderItemAllocation::reallocateFor($current);
                $reallocated[] = $item->id;
            } catch (\Throwable $e) {
                $failed[$item->id] = $e->getMessage();
            }
        }

        return [
            'reallocated' => $reallocated,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }
}
