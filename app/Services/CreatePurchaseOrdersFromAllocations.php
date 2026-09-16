<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrderItemAllocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chantier Dropshipping, étape D2.6.3 — matérialise en PurchaseOrder/
 * PurchaseOrderItem les décisions de sourcing déjà persistées
 * (SalesOrderItemAllocation, D2.4.7), via les fondations posées en D2.6.2
 * (colonne + relations sur purchase_order_items). Aucune sélection de
 * fournisseur ici : le fournisseur et le coût proviennent exclusivement
 * de la fiche SupplierProductSourcing déjà choisie par l'allocation
 * (SupplierSourcingResolver n'est ni importé ni appelé).
 *
 * Granularité (décision validée) : un PurchaseOrder par fournisseur pour
 * l'ensemble des allocations éligibles traitées dans un même appel,
 * plusieurs PurchaseOrderItem si nécessaire — cohérent avec supplier_id
 * unique par PurchaseOrder et sales_order_item_allocation_id UNIQUE par
 * PurchaseOrderItem (D2.6.2).
 *
 * Idempotence, trois niveaux, aucun redondant :
 * 1. Filtrage applicatif via allocation()->purchaseOrderItem() (relation
 *    D2.6.2) AVANT toute écriture — jamais de requête brute équivalente.
 * 2. Reverrouillage (lockForUpdate()) + re-vérification juste avant la
 *    création, sous transaction — élimine la fenêtre de course entre
 *    deux déclenchements concurrents.
 * 3. Contrainte UNIQUE en base (D2.6.2) — dernier rempart.
 *
 * Une transaction PAR FOURNISSEUR, jamais une transaction globale :
 * l'échec d'un groupe (un PurchaseOrder) n'affecte jamais les autres
 * groupes déjà créés ou restant à créer.
 *
 * reference : PurchaseOrder.reference est NOT NULL + UNIQUE en base,
 * sans aucun défaut ni au niveau modèle ni en base (sa génération vit
 * uniquement dans PurchaseOrderForm.php, jamais atteinte par un create()
 * direct) - reproduite ici à l'identique ('BC-'.date.'-'.aléatoire) pour
 * rester cohérente avec toutes les références déjà en circulation.
 *
 * Hors périmètre (délibérément absent de ce fichier) : aucun
 * StockMovement, aucun changement de statut SalesOrder, aucun appel à
 * PurchaseOrder::markAsOrdered()/receive()/cancel() (le PurchaseOrder
 * généré reste en DRAFT), aucune expédition, aucune API fournisseur,
 * aucune référence à `activity`.
 */
class CreatePurchaseOrdersFromAllocations
{
    /**
     * @param  Collection<int, SalesOrderItemAllocation>  $allocations
     * @return array{created: PurchaseOrder[], skipped: int[], failed: array<int, string>}
     */
    public function execute(Collection $allocations): array
    {
        $eligible = $allocations->filter(fn (SalesOrderItemAllocation $a) => $a->purchaseOrderItem === null);
        $skipped = $allocations->diff($eligible)->pluck('id')->all();

        $bySupplier = $eligible->groupBy(
            fn (SalesOrderItemAllocation $a) => $a->supplierProductSourcing->supplier_id
        );

        $created = [];
        $failed = [];

        foreach ($bySupplier as $supplierId => $group) {
            try {
                $created[] = DB::transaction(function () use ($supplierId, $group) {
                    $purchaseOrder = PurchaseOrder::create([
                        'supplier_id' => $supplierId,
                        'reference' => 'BC-'.now()->format('Ymd').'-'.strtoupper(Str::random(4)),
                    ]);

                    foreach ($group as $allocation) {
                        // Idempotence, niveau 2 : reverrouillage sous
                        // transaction avant écriture (même principe que
                        // SalesOrderItemAllocation::recordFor()).
                        $locked = SalesOrderItemAllocation::whereKey($allocation->id)
                            ->lockForUpdate()
                            ->first();

                        if ($locked->purchaseOrderItem()->exists()) {
                            continue;
                        }

                        $item = $locked->salesOrderItem;
                        $sourcing = $locked->supplierProductSourcing;

                        PurchaseOrderItem::create([
                            'purchase_order_id' => $purchaseOrder->id,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity_ordered' => $locked->quantity,
                            'unit_price' => $sourcing->supplier_cost,
                            'sales_order_item_allocation_id' => $locked->id,
                        ]);
                    }

                    return $purchaseOrder;
                });
            } catch (\Throwable $e) {
                // Une transaction PAR FOURNISSEUR : l'échec d'un groupe
                // n'affecte jamais les autres.
                $failed[$supplierId] = $e->getMessage();
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
    }
}
