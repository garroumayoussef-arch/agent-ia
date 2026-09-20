<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Illuminate\Database\Eloquent\Collection;
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
 * D2.14 remplace le regroupement par fournisseur seul par un regroupement
 * fournisseur/SalesOrder pour l'ensemble des allocations
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

    /** @param Supplier[] $suppliers */
    private function createAllocationsForOneOrder(array $suppliers): Collection
    {
        $order = SalesOrder::factory()->create();
        $items = [];
        foreach ($suppliers as $supplier) {
            $product = Product::factory()->create();
            $product->supplierSourcings()->create([
                'supplier_id' => $supplier->id,
                'supplier_cost' => 42.50,
                'is_active' => true,
            ]);
            $items[] = SalesOrderItem::factory()->create([
                'sales_order_id' => $order->id,
                'product_id' => $product->id,
                'quantity_ordered' => count($items) + 2,
            ]);
        }
        $order->markAsConfirmed();

        return new Collection(array_map(fn ($item) => SalesOrderItemAllocation::recordFor($item->fresh()), $items));
    }

    private function assertPurchaseOrderContains(PurchaseOrder $purchaseOrder, Collection $allocations): void
    {
        $items = $purchaseOrder->items()->get();
        $this->assertEqualsCanonicalizing($allocations->values()->modelKeys(), $items->pluck('sales_order_item_allocation_id')->all());
        $this->assertSame(
            [$allocations->first()->salesOrderItem->sales_order_id],
            $items->map(fn ($item) => $item->allocation->salesOrderItem->sales_order_id)->unique()->values()->all()
        );
        foreach ($items as $item) {
            $allocation = $allocations->find($item->sales_order_item_allocation_id);
            $this->assertSame($allocation->supplierProductSourcing->supplier_id, $purchaseOrder->supplier_id);
            $this->assertSame($allocation->quantity, $item->quantity_ordered);
            $this->assertEquals($allocation->supplierProductSourcing->supplier_cost, $item->unit_price);
        }
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
     * 2. Deux allocations, meme fournisseur ET meme vente -> un PurchaseOrder
     * =================================================================
     */
    public function test_deux_allocations_meme_fournisseur_sont_regroupees_dans_un_seul_purchase_order(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $allocations = $this->createAllocationsForOneOrder([$supplier, $supplier]);

        $result = (new CreatePurchaseOrdersFromAllocations)->execute($allocations);

        $this->assertCount(1, $result['created']);
        $this->assertSame(2, $result['created'][0]->items()->count());
        $this->assertPurchaseOrderContains($result['created'][0], $allocations);
    }

    /*
     * =================================================================
     * 3. Deux allocations, fournisseurs differents -> deux PurchaseOrder
     * =================================================================
     */
    public function test_deux_allocations_fournisseurs_differents_generent_deux_purchase_orders(): void
    {
        $suppliers = Supplier::factory()->count(2)->create(['name' => fake()->company()]);
        $allocations = $this->createAllocationsForOneOrder($suppliers->all());

        $result = (new CreatePurchaseOrdersFromAllocations)->execute($allocations);

        $this->assertCount(2, $result['created']);
        $this->assertNotSame($result['created'][0]->supplier_id, $result['created'][1]->supplier_id);
        foreach ($result['created'] as $purchaseOrder) {
            $this->assertPurchaseOrderContains($purchaseOrder, $allocations->filter(
                fn ($allocation) => $allocation->supplierProductSourcing->supplier_id === $purchaseOrder->supplier_id
            ));
        }
    }

    public function test_meme_fournisseur_deux_ventes_sont_separees_et_annulables_independamment(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $a = $this->createAllocation($supplier);
        $b = $this->createAllocation($supplier);

        $result = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$a, $b]));

        $this->assertCount(2, $result['created']);
        $this->assertSame([], $result['failed']);
        $this->assertPurchaseOrderContains($result['created'][0], new Collection([$a]));
        $this->assertPurchaseOrderContains($result['created'][1], new Collection([$b]));
        foreach ($result['created'] as $purchaseOrder) {
            $this->assertSame(PurchaseOrder::STATUS_DRAFT, $purchaseOrder->status);
        }
        $result['created'][0]->cancel();
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $result['created'][0]->fresh()->status);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $result['created'][1]->fresh()->status);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $a->salesOrderItem->salesOrder->fresh()->status);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $b->salesOrderItem->salesOrder->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_lot_croise_et_rejeu_conservent_exactement_les_couples_presents(): void
    {
        [$supplierA, $supplierB] = Supplier::factory()->count(2)->create(['name' => fake()->company()])->all();
        $orderA = $this->createAllocationsForOneOrder([$supplierA, $supplierA, $supplierB]);
        $orderB = $this->createAllocationsForOneOrder([$supplierA]);
        $allocations = $orderA->merge($orderB);
        $expected = [new Collection([$orderA[0], $orderA[1]]), new Collection([$orderA[2]]), $orderB];
        $service = new CreatePurchaseOrdersFromAllocations;

        $result = $service->execute($allocations);

        $this->assertCount(3, $result['created']);
        $this->assertSame([], $result['failed']);
        foreach ($expected as $index => $group) {
            $this->assertPurchaseOrderContains($result['created'][$index], $group);
        }
        $this->assertDatabaseCount('purchase_order_items', 4);
        $replayed = $service->execute($allocations->fresh());
        $this->assertSame([], $replayed['created']);
        $this->assertSame([], $replayed['failed']);
        $this->assertEqualsCanonicalizing($allocations->modelKeys(), $replayed['skipped']);
        $this->assertDatabaseCount('purchase_orders', 3);
        $this->assertDatabaseCount('purchase_order_items', 4);
    }

    public function test_lot_partiellement_converti_ne_fusionne_pas_avec_un_achat_existant(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $sameOrder = $this->createAllocationsForOneOrder([$supplier, $supplier]);
        $other = $this->createAllocation($supplier);
        $service = new CreatePurchaseOrdersFromAllocations;
        $existing = $service->execute(new Collection([$sameOrder[0]]))['created'][0];

        $result = $service->execute($sameOrder->merge([$other])->fresh());

        $this->assertSame([$sameOrder[0]->id], $result['skipped']);
        $this->assertCount(2, $result['created']);
        $this->assertSame([], $result['failed']);
        $this->assertPurchaseOrderContains($existing, new Collection([$sameOrder[0]]));
        $this->assertPurchaseOrderContains($result['created'][0], new Collection([$sameOrder[1]]));
        $this->assertPurchaseOrderContains($result['created'][1], new Collection([$other]));
        $this->assertDatabaseCount('purchase_orders', 3);
        $this->assertDatabaseCount('purchase_order_items', 3);
    }

    public function test_echec_dun_couple_annule_toutes_ses_lignes_et_preserve_les_autres(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $before = $this->createAllocation($supplier);
        $failing = $this->createAllocationsForOneOrder([$supplier, $supplier]);
        $after = $this->createAllocation($supplier);
        // Une variante apparue après l'allocation provoque un vrai rejet
        // métier sur la seconde ligne, après insertion de la première.
        ProductVariant::factory()->create(['product_id' => $failing[1]->salesOrderItem->product_id]);

        $result = (new CreatePurchaseOrdersFromAllocations)->execute(
            (new Collection([$before]))->merge($failing)->merge([$after])
        );

        $key = $supplier->id.':'.$failing[0]->salesOrderItem->sales_order_id;
        $this->assertSame([$key], array_keys($result['failed']));
        $this->assertStringContainsString('Ce produit possède des variantes', $result['failed'][$key]);
        $this->assertCount(2, $result['created']);
        $this->assertPurchaseOrderContains($result['created'][0], new Collection([$before]));
        $this->assertPurchaseOrderContains($result['created'][1], new Collection([$after]));
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->assertDatabaseCount('purchase_order_items', 2);
        foreach ($failing as $allocation) {
            $this->assertNull($allocation->fresh()->purchaseOrderItem);
        }
    }

    public function test_deux_echecs_du_meme_fournisseur_ne_secrasent_pas(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $allocations = new Collection([$this->createAllocation($supplier), $this->createAllocation($supplier)]);
        foreach ($allocations as $allocation) {
            ProductVariant::factory()->create(['product_id' => $allocation->salesOrderItem->product_id]);
        }

        $result = (new CreatePurchaseOrdersFromAllocations)->execute($allocations);

        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['skipped']);
        $this->assertEqualsCanonicalizing(
            $allocations->map(fn ($allocation) => $supplier->id.':'.$allocation->salesOrderItem->sales_order_id)->all(),
            array_keys($result['failed'])
        );
        foreach ($result['failed'] as $message) {
            $this->assertStringContainsString('Ce produit possède des variantes', $message);
        }
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->assertDatabaseCount('purchase_order_items', 0);
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

    public function test_d2_15_rejeu_des_memes_instances_ne_cree_aucun_achat_vide(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $allocations = $this->createAllocationsForOneOrder([$supplier, $supplier]);
        $allocations->load('purchaseOrderItem');
        $service = new CreatePurchaseOrdersFromAllocations;
        $existing = $service->execute($allocations)['created'][0];

        foreach ($allocations as $allocation) {
            $this->assertTrue($allocation->relationLoaded('purchaseOrderItem'));
            $this->assertNull($allocation->purchaseOrderItem);
        }
        // Les instances restent périmées : tout le couple sera ignoré
        // seulement lors du recontrôle transactionnel.
        $result = $service->execute($allocations);

        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['failed']);
        $this->assertEqualsCanonicalizing($allocations->modelKeys(), $result['skipped']);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_order_items', 2);
        $this->assertPurchaseOrderContains($existing, $allocations);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $existing->fresh()->status);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $allocations[0]->salesOrderItem->salesOrder->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_d2_15_recontrole_partiel_regroupe_uniquement_les_lignes_restantes(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $allocations = $this->createAllocationsForOneOrder([$supplier, $supplier, $supplier]);
        $allocations->load('purchaseOrderItem');
        $service = new CreatePurchaseOrdersFromAllocations;
        $existing = $service->execute(new Collection([$allocations[0]]))['created'][0];
        $other = $this->createAllocation($supplier);

        $result = $service->execute(new Collection([
            $allocations[0], $allocations[1], $allocations[0], $allocations[2], $other,
        ]));

        $this->assertSame([$allocations[0]->id], $result['skipped']);
        $this->assertSame([], $result['failed']);
        $this->assertCount(2, $result['created']);
        $this->assertPurchaseOrderContains($existing, new Collection([$allocations[0]]));
        $this->assertPurchaseOrderContains($result['created'][0], new Collection([$allocations[1], $allocations[2]]));
        $this->assertPurchaseOrderContains($result['created'][1], new Collection([$other]));
        $this->assertNotSame($existing->id, $result['created'][0]->id);
        $this->assertDatabaseCount('purchase_orders', 3);
        $this->assertDatabaseCount('purchase_order_items', 4);
        foreach ($result['created'] as $purchaseOrder) {
            $this->assertSame(PurchaseOrder::STATUS_DRAFT, $purchaseOrder->status);
        }
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_d2_15_skipped_reunit_filtrage_initial_et_recontrole_sans_doublon(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $allocations = $this->createAllocationsForOneOrder([$supplier, $supplier]);
        $allocations->load('purchaseOrderItem');
        $service = new CreatePurchaseOrdersFromAllocations;
        $service->execute($allocations);
        $fresh = $allocations[0]->fresh();

        $result = $service->execute(new Collection([$fresh, $fresh, $allocations[1], $allocations[1]]));

        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['failed']);
        $this->assertEqualsCanonicalizing($allocations->modelKeys(), $result['skipped']);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_order_items', 2);
    }

    public function test_d2_16_rollback_conserve_skipped_avec_instances_fraiches_et_perimees(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $allocations = $this->createAllocationsForOneOrder([$supplier, $supplier, $supplier]);
        $allocations->load('purchaseOrderItem');
        $service = new CreatePurchaseOrdersFromAllocations;
        $existing = $service->execute(new Collection([$allocations[0]]))['created'][0];
        $existingAttributes = $existing->fresh()->getAttributes();
        $existingItemAttributes = $existing->items()->first()->getAttributes();
        ProductVariant::factory()->create(['product_id' => $allocations[2]->salesOrderItem->product_id]);
        $key = $supplier->id.':'.$allocations[0]->salesOrderItem->sales_order_id;
        $results = [];

        foreach ([true, false] as $fresh) {
            $first = $fresh ? $allocations[0]->fresh() : $allocations[0];
            $result = $service->execute(new Collection([
                $first, $first, $allocations[1], $allocations[1], $allocations[2],
            ]));
            $results[] = $result;

            $this->assertSame([], $result['created']);
            $this->assertSame([$allocations[0]->id], $result['skipped']);
            $this->assertSame([
                $key => 'Ce produit possède des variantes : veuillez sélectionner la variante concernée par cette ligne.',
            ], $result['failed']);
            $this->assertSame($existingAttributes, $existing->fresh()->getAttributes());
            $this->assertSame($existingItemAttributes, $existing->items()->first()->getAttributes());
            $this->assertNull($allocations[1]->fresh()->purchaseOrderItem);
            $this->assertNull($allocations[2]->fresh()->purchaseOrderItem);
            $this->assertDatabaseCount('purchase_orders', 1);
            $this->assertDatabaseCount('purchase_order_items', 1);
        }

        $this->assertSame($results[0], $results[1]);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $allocations[0]->salesOrderItem->salesOrder->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_d2_16_rollback_ne_pollue_pas_les_couples_voisins_du_meme_fournisseur(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $before = $this->createAllocation($supplier);
        $group = $this->createAllocationsForOneOrder([$supplier, $supplier, $supplier]);
        $after = $this->createAllocation($supplier);
        $group->load('purchaseOrderItem');
        $service = new CreatePurchaseOrdersFromAllocations;
        $existing = $service->execute(new Collection([$group[0]]))['created'][0];
        ProductVariant::factory()->create(['product_id' => $group[2]->salesOrderItem->product_id]);

        $result = $service->execute(new Collection([
            $before, $group[0], $group[1], $group[1], $group[2], $after,
        ]));

        $this->assertSame([$group[0]->id], $result['skipped']);
        $this->assertSame([
            $supplier->id.':'.$group[0]->salesOrderItem->sales_order_id
                => 'Ce produit possède des variantes : veuillez sélectionner la variante concernée par cette ligne.',
        ], $result['failed']);
        $this->assertCount(2, $result['created']);
        $this->assertPurchaseOrderContains($existing, new Collection([$group[0]]));
        $this->assertPurchaseOrderContains($result['created'][0], new Collection([$before]));
        $this->assertPurchaseOrderContains($result['created'][1], new Collection([$after]));
        $this->assertNull($group[1]->fresh()->purchaseOrderItem);
        $this->assertNull($group[2]->fresh()->purchaseOrderItem);
        $this->assertDatabaseCount('purchase_orders', 3);
        $this->assertDatabaseCount('purchase_order_items', 3);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_d2_16_rollback_ne_signale_pas_une_conversion_non_examinee_apres_exception(): void
    {
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $group = $this->createAllocationsForOneOrder([$supplier, $supplier, $supplier]);
        $group->load('purchaseOrderItem');
        $service = new CreatePurchaseOrdersFromAllocations;
        $existing = $service->execute(new Collection([$group[2]]))['created'][0];
        $existingAttributes = $existing->fresh()->getAttributes();
        $existingItemAttributes = $existing->items()->first()->getAttributes();
        ProductVariant::factory()->create(['product_id' => $group[1]->salesOrderItem->product_id]);

        $result = $service->execute(new Collection([$group[0], $group[0], $group[1], $group[2]]));

        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame([
            $supplier->id.':'.$group[0]->salesOrderItem->sales_order_id
                => 'Ce produit possède des variantes : veuillez sélectionner la variante concernée par cette ligne.',
        ], $result['failed']);
        $this->assertSame($existingAttributes, $existing->fresh()->getAttributes());
        $this->assertSame($existingItemAttributes, $existing->items()->first()->getAttributes());
        $this->assertNull($group[0]->fresh()->purchaseOrderItem);
        $this->assertNull($group[1]->fresh()->purchaseOrderItem);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_order_items', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_d2_16_doublon_cree_dans_un_appel_reussi_conserve_le_resultat_existant(): void
    {
        $allocation = $this->createAllocation();

        $result = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation, $allocation]));

        $this->assertCount(1, $result['created']);
        $this->assertSame([$allocation->id], $result['skipped']);
        $this->assertSame([], $result['failed']);
        $this->assertPurchaseOrderContains($result['created'][0], new Collection([$allocation]));
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_order_items', 1);
    }

    /*
     * =================================================================
     * 6. Suppression d'une allocation convertie -> bloquee
     *
     * Chantier Dropshipping, étape D2.9 (spécification validée) —
     * SalesOrderItemAllocation bloque désormais TOUTE suppression dès la
     * création (garde applicative inconditionnelle sur booted(),
     * cf. SalesOrderItemAllocation::deleting()), qu'une allocation soit
     * convertie ou non : cette garde intercepte l'appel AVANT que la
     * requête SQL ne soit émise, donc avant que la contrainte
     * restrictOnDelete() de purchase_order_items.
     * sales_order_item_allocation_id (D2.6.2, toujours en place et
     * toujours vraie, cf. migration inchangée) ne puisse jamais être
     * atteinte. La protection est strictement plus forte qu'avant D2.9
     * (elle s'applique désormais même à une allocation JAMAIS convertie),
     * seul le type d'exception observé change : \Exception explicite au
     * lieu de QueryException brute.
     * =================================================================
     */
    public function test_suppression_dune_allocation_convertie_est_bloquee(): void
    {
        $allocation = $this->createAllocation();
        (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Une allocation de sourcing ne peut pas être supprimée après son enregistrement.');

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
