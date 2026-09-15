<?php

namespace App\Filament\Resources\SalesOrders\Concerns;

use App\Models\SalesOrder;
use App\Models\SalesOrderItemAllocation;

trait HasSalesOrderSourcingAction
{
    /**
     * Chantier Dropshipping, étape D2.5.2 — orchestration EXPLICITE du
     * sourcing pour une SalesOrder : appelle
     * SalesOrderItemAllocation::recordFor() (D2.4.7, inchangé) pour
     * chaque ligne éligible. Aucune règle de sélection de fournisseur
     * ici — celle-ci reste intégralement dans SupplierSourcingResolver
     * (D2.4.6), jamais dupliquée, jamais référencée directement (ce
     * Trait ne connaît que recordFor()).
     *
     * Idempotence : $item->allocation()->exists() est vérifié AVANT tout
     * appel à recordFor() — une ligne déjà allouée est ignorée
     * silencieusement, jamais un second appel.
     *
     * Traitement séquentiel, sans transaction englobante : chaque
     * recordFor() gère déjà sa propre transaction/verrouillage (D2.4.7).
     * Une exception sur une ligne est capturée individuellement et
     * n'interrompt jamais le traitement des lignes suivantes.
     *
     * Ne modifie ni le statut de la SalesOrder, ni aucune autre donnée
     * que celles écrites par recordFor() lui-même. Aucune notion
     * d'autorisation/visibilité ici : c'est la responsabilité de
     * l'action Filament elle-même (étape D2.5.3, non réalisée ici).
     *
     * @return array{allocated: int[], skipped: int[], failed: array<int, string>}
     */
    protected function orchestrateSourcing(SalesOrder $record): array
    {
        $allocated = [];
        $skipped = [];
        $failed = [];

        foreach ($record->items as $item) {
            if ($item->allocation()->exists()) {
                $skipped[] = $item->id;

                continue;
            }

            try {
                SalesOrderItemAllocation::recordFor($item);
                $allocated[] = $item->id;
            } catch (\Throwable $e) {
                $failed[$item->id] = $e->getMessage();
            }
        }

        return [
            'allocated' => $allocated,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }
}
