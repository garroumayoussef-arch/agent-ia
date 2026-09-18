<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItemReturn;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier Dropshipping, Gap D — test de caractérisation de l'agrégation
 * READ-ONLY de l'origine (achat direct / dropshipping) dans
 * PurchaseOrdersTable (vue liste), distincte de
 * PurchaseOrderInfolistSourcingTraceabilityTest (vue détail, D2.8.1,
 * inchangée). Vérifie exclusivement PurchaseOrdersTable::originOverview() :
 * aucune règle de sourcing n'est réévaluée, aucune donnée n'est modifiée.
 *
 * Libellé du cas mixte verrouillé par l'opérateur humain (Gap D, Q2) :
 * "Vente {référence} + achat direct".
 */
class PurchaseOrdersTableOriginOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $warehouse = Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $manager = User::factory()->create()->assignRole('manager');
        // Chantier Dropshipping, étape D2.12 — nécessaire aux scénarios
        // de ré-allocation (PurchaseOrder::receive() /
        // PurchaseOrderItemReturn::recordFor()), sans impact sur les
        // scénarios Gap D existants (aucun ne touche au stock).
        $manager->warehouses()->attach($warehouse->id);
        $this->actingAs($manager);
    }

    /*
     * =================================================================
     * 1. Bon de commande sans aucune ligne : cas dégénéré, "Achat direct"
     *    par défaut (aucune référence de vente ne peut exister).
     * =================================================================
     */
    public function test_bon_sans_ligne_agrege_en_achat_direct(): void
    {
        $purchaseOrder = PurchaseOrder::factory()->create();

        $this->assertSame(
            'Achat direct',
            PurchaseOrdersTable::originOverview($purchaseOrder->fresh(['items.allocation.salesOrderItem.salesOrder']))
        );
    }

    /*
     * =================================================================
     * 2. Toutes les lignes sont un achat direct (aucune allocation) :
     *    "Achat direct".
     * =================================================================
     */
    public function test_toutes_lignes_achat_direct_agrege_en_achat_direct(): void
    {
        $purchaseOrder = PurchaseOrder::factory()
            ->has(\App\Models\PurchaseOrderItem::factory()->count(2), 'items')
            ->create();

        $this->assertSame(
            'Achat direct',
            PurchaseOrdersTable::originOverview($purchaseOrder->fresh(['items.allocation.salesOrderItem.salesOrder']))
        );
    }

    /*
     * =================================================================
     * 3. Toutes les lignes proviennent de la même vente (dropshipping
     *    uniforme, cas garanti par le code : une génération = un seul
     *    SalesOrder) : "Vente {référence}".
     * =================================================================
     */
    public function test_toutes_lignes_meme_vente_agrege_en_vente_reference(): void
    {
        [$order, $purchaseOrder] = $this->createDropshippingOrder(['A', 'B']);

        $this->assertSame(
            "Vente {$order->reference}",
            PurchaseOrdersTable::originOverview($purchaseOrder->fresh(['items.allocation.salesOrderItem.salesOrder']))
        );
    }

    /*
     * =================================================================
     * 4. Mélange "Vente X" + "Achat direct" sur le même bon (ajout
     *    manuel d'une ligne à un bon dropshipping resté en brouillon,
     *    cf. PurchaseOrderForm) : libellé combiné verrouillé (Gap D, Q2).
     * =================================================================
     */
    public function test_melange_vente_et_achat_direct_agrege_en_libelle_combine(): void
    {
        [$order, $purchaseOrder] = $this->createDropshippingOrder(['A']);

        $purchaseOrder->items()->create([
            'product_id' => Product::factory()->create()->id,
            'quantity_ordered' => 3,
            'unit_price' => 10,
        ]);

        $this->assertSame(
            "Vente {$order->reference} + achat direct",
            PurchaseOrdersTable::originOverview($purchaseOrder->fresh(['items.allocation.salesOrderItem.salesOrder']))
        );
    }

    /**
     * @param  string[]  $supplierSuffixes
     * @return array{0: SalesOrder, 1: PurchaseOrder}
     */
    private function createDropshippingOrder(array $supplierSuffixes): array
    {
        $order = SalesOrder::factory()->create();

        // Toutes les lignes doivent être créées AVANT l'unique appel à
        // markAsConfirmed() : SalesOrder::markAsConfirmed() exige qu'au
        // moins une ligne existe déjà et ne peut être appelée qu'une
        // fois (une commande confirmée n'est plus en brouillon).
        $items = [];

        foreach ($supplierSuffixes as $suffix) {
            $product = Product::factory()->create();
            $product->supplierSourcings()->create([
                'supplier_id' => Supplier::factory()->create(['name' => "Fournisseur {$suffix}"])->id,
                'is_active' => true,
            ]);

            $items[] = SalesOrderItem::factory()->create([
                'sales_order_id' => $order->id,
                'product_id' => $product->id,
            ]);
        }

        $order->markAsConfirmed();

        $allocationIds = array_map(
            fn (SalesOrderItem $item) => SalesOrderItemAllocation::recordFor($item->fresh())->id,
            $items
        );

        (new CreatePurchaseOrdersFromAllocations)->execute(
            SalesOrderItemAllocation::query()->whereIn('id', $allocationIds)->get()
        );

        return [$order->fresh(), PurchaseOrder::firstOrFail()];
    }

    /**
     * Chantier Dropshipping, étape D2.12 — construit une allocation
     * intégralement ré-allouée (D2.9) vers $nouveauFournisseur, à partir
     * d'un ancien PurchaseOrder chez un fournisseur nommé
     * $ancienFournisseurName. Retourne [ancienPurchaseOrder,
     * nouvelleAllocation] — la génération du NOUVEAU PurchaseOrder est
     * laissée à l'appelant (via CreatePurchaseOrdersFromAllocations),
     * pour permettre de regrouper plusieurs ré-allocations vers le même
     * $nouveauFournisseur sur un seul appel (scénario multi-références).
     *
     * @return array{0: PurchaseOrder, 1: SalesOrderItemAllocation}
     */
    private function createReallocatedAllocationTo(
        Supplier $nouveauFournisseur,
        string $ancienFournisseurName,
        int $quantity = 5,
    ): array {
        $product = Product::factory()->create();
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => $ancienFournisseurName])->id,
            'is_active' => true,
        ]);
        $product->supplierSourcings()->create([
            'supplier_id' => $nouveauFournisseur->id,
            'is_active' => true,
        ]);

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => $quantity,
        ]);
        $order->markAsConfirmed();

        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        $ancienPurchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]))['created'][0];
        $ancienPurchaseOrder->markAsOrdered();
        $ancienItem = $ancienPurchaseOrder->items()->first();
        $ancienPurchaseOrder->fresh()->receive([$ancienItem->id => $quantity]);
        PurchaseOrderItemReturn::recordFor($ancienItem->fresh(), $quantity, now()->toDateString());

        $nouvelleAllocation = SalesOrderItemAllocation::reallocateFor($allocation->fresh());

        return [$ancienPurchaseOrder->fresh(), $nouvelleAllocation];
    }

    /*
     * =================================================================
     * D2.12 — agrégation READ-ONLY de la ré-allocation (D2.9/D2.11),
     * règle multi-références validée : 0/1/plusieurs, sans doublon
     * =================================================================
     */

    /**
     * 6. Aucune ligne ré-allouée (dropshipping simple) : liste vide,
     *    aucun badge affiché — non-régression sur les scénarios Gap D
     *    existants (1 à 4 ci-dessus).
     */
    public function test_aucune_ligne_reallouee_agrege_en_liste_vide(): void
    {
        [, $purchaseOrder] = $this->createDropshippingOrder(['A']);

        $this->assertSame(
            [],
            PurchaseOrdersTable::replacedPurchaseOrderOverview(
                $purchaseOrder->fresh(['items.allocation.replacesAllocation.purchaseOrderItem.purchaseOrder'])
            )
        );
    }

    /**
     * 7. Une seule référence remplacée : liste à un élément.
     */
    public function test_une_seule_reference_remplacee_agrege_en_liste_a_un_element(): void
    {
        $nouveauFournisseur = Supplier::factory()->create(['name' => 'Fournisseur Eta']);
        [$ancienPurchaseOrder, $nouvelleAllocation] = $this->createReallocatedAllocationTo($nouveauFournisseur, 'Fournisseur Zeta');

        $nouveauPurchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$nouvelleAllocation]))['created'][0];

        $this->assertSame(
            [$ancienPurchaseOrder->reference],
            PurchaseOrdersTable::replacedPurchaseOrderOverview(
                $nouveauPurchaseOrder->fresh(['items.allocation.replacesAllocation.purchaseOrderItem.purchaseOrder'])
            )
        );
    }

    /**
     * 8. Plusieurs références remplacées DISTINCTES, regroupées sur le
     *    MÊME nouveau PurchaseOrder (deux ré-allocations indépendantes
     *    vers le même fournisseur) : toutes les références apparaissent,
     *    aucune n'est masquée.
     */
    public function test_plusieurs_references_remplacees_distinctes_agregent_toutes_sans_doublon(): void
    {
        $nouveauFournisseur = Supplier::factory()->create(['name' => 'Fournisseur Commun']);
        [$ancienPurchaseOrderA, $allocationA] = $this->createReallocatedAllocationTo($nouveauFournisseur, 'Fournisseur A');
        [$ancienPurchaseOrderB, $allocationB] = $this->createReallocatedAllocationTo($nouveauFournisseur, 'Fournisseur B');

        $nouveauPurchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(
            new Collection([$allocationA, $allocationB])
        )['created'][0];

        $references = PurchaseOrdersTable::replacedPurchaseOrderOverview(
            $nouveauPurchaseOrder->fresh(['items.allocation.replacesAllocation.purchaseOrderItem.purchaseOrder'])
        );

        $this->assertCount(2, $references);
        $this->assertContains($ancienPurchaseOrderA->reference, $references);
        $this->assertContains($ancienPurchaseOrderB->reference, $references);
    }

    /**
     * 9. Déduplication réelle : deux lignes du nouveau PurchaseOrder
     *    remplacent chacune une allocation différente, mais ces deux
     *    allocations avaient été converties dans le MÊME ancien
     *    PurchaseOrder (même fournisseur d'origine) — la référence ne
     *    doit apparaître qu'une seule fois, jamais dupliquée.
     */
    public function test_deux_lignes_remplacant_le_meme_ancien_bon_ne_dupliquent_pas_la_reference(): void
    {
        $ancienFournisseur = Supplier::factory()->create(['name' => 'Fournisseur Commun Ancien']);
        $nouveauFournisseur = Supplier::factory()->create(['name' => 'Fournisseur Commun Nouveau']);

        $productA = Product::factory()->create();
        $productA->supplierSourcings()->create(['supplier_id' => $ancienFournisseur->id, 'is_active' => true]);
        $productA->supplierSourcings()->create(['supplier_id' => $nouveauFournisseur->id, 'is_active' => true]);

        $productB = Product::factory()->create();
        $productB->supplierSourcings()->create(['supplier_id' => $ancienFournisseur->id, 'is_active' => true]);
        $productB->supplierSourcings()->create(['supplier_id' => $nouveauFournisseur->id, 'is_active' => true]);

        $order = SalesOrder::factory()->create();
        $itemA = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $productA->id, 'quantity_ordered' => 3]);
        $itemB = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $productB->id, 'quantity_ordered' => 2]);
        $order->markAsConfirmed();

        $allocationA = SalesOrderItemAllocation::recordFor($itemA->fresh());
        $allocationB = SalesOrderItemAllocation::recordFor($itemB->fresh());

        // Même fournisseur ancien pour A et B : un seul appel les
        // regroupe dans le MÊME ancien PurchaseOrder.
        $ancienPurchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(
            new Collection([$allocationA, $allocationB])
        )['created'][0];
        $ancienPurchaseOrder->markAsOrdered();

        $ancienItems = $ancienPurchaseOrder->items;
        $ancienPurchaseOrder->fresh()->receive(
            $ancienItems->mapWithKeys(fn ($item) => [$item->id => $item->quantity_ordered])->all()
        );
        foreach ($ancienItems as $ancienItem) {
            PurchaseOrderItemReturn::recordFor($ancienItem->fresh(), $ancienItem->quantity_ordered, now()->toDateString());
        }

        $nouvelleAllocationA = SalesOrderItemAllocation::reallocateFor($allocationA->fresh());
        $nouvelleAllocationB = SalesOrderItemAllocation::reallocateFor($allocationB->fresh());

        $nouveauPurchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(
            new Collection([$nouvelleAllocationA, $nouvelleAllocationB])
        )['created'][0];

        $references = PurchaseOrdersTable::replacedPurchaseOrderOverview(
            $nouveauPurchaseOrder->fresh(['items.allocation.replacesAllocation.purchaseOrderItem.purchaseOrder'])
        );

        // Deux lignes, mais UNE SEULE référence remplacée (même ancien
        // PurchaseOrder pour les deux) : la déduplication doit collapser
        // le résultat à un seul élément.
        $this->assertSame([$ancienPurchaseOrder->reference], $references);
    }

    /*
     * =================================================================
     * 10. Non-régression N+1 : la liste des bons de commande ne doit
     *    déclencher qu'un nombre borné de requêtes SQL, indépendant du
     *    nombre de bons/lignes/références distinctes affichés, grâce à
     *    modifyQueryUsing().
     * =================================================================
     */
    public function test_liste_des_bons_ne_provoque_pas_de_n_plus_1(): void
    {
        foreach (range(1, 5) as $i) {
            $this->createDropshippingOrder(["S{$i}"]);
        }

        // Chantier Dropshipping, étape D2.12 — inclut un bon avec
        // PLUSIEURS références remplacées distinctes parmi les 5, pour
        // démontrer que replaced_purchase_order_overview ne coûte pas de
        // requête supplémentaire liée au nombre de références distinctes
        // (déduplication strictement en mémoire).
        $nouveauFournisseur = Supplier::factory()->create(['name' => 'Fournisseur N+1 Commun']);
        [, $allocationX] = $this->createReallocatedAllocationTo($nouveauFournisseur, 'Fournisseur N+1 X');
        [, $allocationY] = $this->createReallocatedAllocationTo($nouveauFournisseur, 'Fournisseur N+1 Y');
        (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocationX, $allocationY]));

        DB::enableQueryLog();

        Livewire::test(ListPurchaseOrders::class)->assertSuccessful();

        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertLessThan(
            30,
            $queryCount,
            "Nombre de requêtes SQL anormalement élevé ({$queryCount}) : suspicion de N+1 sur les colonnes 'origin_overview'/'replaced_purchase_order_overview'."
        );
    }
}
