<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.6.1 — test de caractérisation de la
 * future classe App\Services\CreatePurchaseOrdersFromAllocations
 * (D2.6.2 : migration + relations additives, D2.6.3 : la classe
 * elle-même), ÉCRIT AVANT TOUT CODE D2.6.2/D2.6.3 — même discipline que
 * D2.2/D2.3.1/D2.4.1/D2.5.1.
 *
 * Ni la classe, ni la colonne purchase_order_items.
 * sales_order_item_allocation_id, ni les relations
 * PurchaseOrderItem::allocation()/SalesOrderItemAllocation::
 * purchaseOrderItem() n'existent à ce stade : ce fichier est donc, par
 * construction, intégralement ROUGE tant que D2.6.2/D2.6.3 n'ont pas
 * été réalisés. Il doit devenir vert à l'identique une fois ces étapes
 * terminées, sans qu'aucune assertion ci-dessous n'ait besoin d'être
 * modifiée.
 *
 * Décisions d'architecture caractérisées (validées avant implémentation) :
 * un PurchaseOrder par fournisseur pour l'ensemble des allocations
 * converties dans un même appel ; idempotence à deux niveaux (filtrage
 * avant écriture + contrainte UNIQUE en base) ; le PurchaseOrder généré
 * reste en DRAFT (aucun appel à markAsOrdered()) ; aucune écriture
 * StockMovement ; aucune nouvelle règle de sélection fournisseur (le
 * fournisseur et le coût proviennent exclusivement de la fiche
 * SupplierProductSourcing déjà choisie par l'allocation D2.4.7, jamais
 * recalculés — SupplierSourcingResolver n'est ni importé ni appelé ici).
 */
class CreatePurchaseOrdersFromAllocationsTest extends TestCase
{
    use RefreshDatabase;

    private function createAllocation(?Supplier $supplier = null, ?Product $product = null): SalesOrderItemAllocation
    {
        $product ??= Product::factory()->create();
        $supplier ??= Supplier::factory()->create(['name' => fake()->company()]);

        $product->supplierSourcings()->create([
            'supplier_id' => $supplier->id,
            'supplier_cost' => 42.50,
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        $order->markAsConfirmed();

        return SalesOrderItemAllocation::recordFor($item->fresh());
    }

    /*
     * =================================================================
     * 1. Une allocation -> un PurchaseOrder + un PurchaseOrderItem
     * =================================================================
     */
    public function test_une_allocation_genere_un_purchase_order_avec_une_ligne(): void
    {
        $allocation = $this->createAllocation();

        $result = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]));

        $this->assertCount(1, $result['created']);
        $purchaseOrder = $result['created'][0];
        $this->assertInstanceOf(PurchaseOrder::class, $purchaseOrder);
        $this->assertSame(1, $purchaseOrder->items()->count());

        $item = $purchaseOrder->items()->first();
        $this->assertSame($allocation->id, $item->sales_order_item_allocation_id);
        $this->assertEquals(42.50, $item->unit_price);
    }

    /*
     * =================================================================
     * 2. Deux allocations, meme fournisseur -> un seul PurchaseOrder
     * =================================================================
     */
    public function test_deux_allocations_meme_fournisseur_sont_regroupees_dans_un_seul_purchase_order(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $allocationA = $this->createAllocation($supplier);
        $allocationB = $this->createAllocation($supplier);

        $result = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocationA, $allocationB]));

        $this->assertCount(1, $result['created']);
        $this->assertSame(2, $result['created'][0]->items()->count());
    }

    /*
     * =================================================================
     * 3. Deux allocations, fournisseurs differents -> deux PurchaseOrder
     * =================================================================
     */
    public function test_deux_allocations_fournisseurs_differents_generent_deux_purchase_orders(): void
    {
        $allocationA = $this->createAllocation();
        $allocationB = $this->createAllocation();

        $result = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocationA, $allocationB]));

        $this->assertCount(2, $result['created']);
        $this->assertNotSame($result['created'][0]->supplier_id, $result['created'][1]->supplier_id);
    }

    /*
     * =================================================================
     * 4. Allocation deja convertie -> ignoree (idempotence, niveau 1)
     * =================================================================
     */
    public function test_une_allocation_deja_convertie_est_ignoree(): void
    {
        $allocation = $this->createAllocation();
        (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]));

        $result = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation->fresh()]));

        $this->assertCount(0, $result['created']);
        $this->assertContains($allocation->id, $result['skipped']);
        $this->assertSame(1, PurchaseOrderItem::count());
    }

    /*
     * =================================================================
     * 5. Rejouer deux fois sur le meme jeu -> no-op total au 2e appel
     * =================================================================
     */
    public function test_rejouer_lexecution_sur_le_meme_jeu_est_un_no_op_total(): void
    {
        $allocation = $this->createAllocation();

        (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]));
        $countApresPremierAppel = PurchaseOrderItem::count();

        (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation->fresh()]));

        $this->assertSame($countApresPremierAppel, PurchaseOrderItem::count());
    }

    /*
     * =================================================================
     * 6. Suppression d'une allocation convertie -> bloquee (restrictOnDelete)
     * =================================================================
     */
    public function test_suppression_dune_allocation_convertie_est_bloquee(): void
    {
        $allocation = $this->createAllocation();
        (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]));

        $this->expectException(QueryException::class);

        $allocation->delete();
    }

    /*
     * =================================================================
     * 7. Absence d'effet de bord - aucun StockMovement, PurchaseOrder
     * reste DRAFT (aucun markAsOrdered() implicite)
     * =================================================================
     */
    public function test_aucun_effet_de_bord_sur_stock_et_le_purchase_order_reste_draft(): void
    {
        $allocation = $this->createAllocation();

        $result = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]));

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $result['created'][0]->status);
    }
}
