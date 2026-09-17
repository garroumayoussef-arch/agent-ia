<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Database\Seeders\RoleSeeder;
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

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));
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

    /*
     * =================================================================
     * 5. Non-régression N+1 : la liste des bons de commande ne doit
     *    déclencher qu'un nombre borné de requêtes SQL, indépendant du
     *    nombre de bons/lignes affichés, grâce à modifyQueryUsing().
     * =================================================================
     */
    public function test_liste_des_bons_ne_provoque_pas_de_n_plus_1(): void
    {
        foreach (range(1, 5) as $i) {
            $this->createDropshippingOrder(["S{$i}"]);
        }

        DB::enableQueryLog();

        Livewire::test(ListPurchaseOrders::class)->assertSuccessful();

        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertLessThan(
            30,
            $queryCount,
            "Nombre de requêtes SQL anormalement élevé ({$queryCount}) : suspicion de N+1 sur la colonne 'origin_overview'."
        );
    }
}
