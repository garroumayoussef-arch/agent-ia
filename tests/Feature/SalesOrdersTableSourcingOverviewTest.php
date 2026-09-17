<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Filament\Resources\SalesOrders\Tables\SalesOrdersTable;
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
 * READ-ONLY du sourcing fournisseur dans SalesOrdersTable (vue liste),
 * distincte de SalesOrderInfolistSourcingTraceabilityTest (vue détail,
 * D2.7/D2.8.2, inchangée). Vérifie exclusivement SalesOrdersTable::
 * sourcingOverviewState()/Label()/Color() : aucune règle de sourcing
 * n'est réévaluée, aucune donnée n'est modifiée.
 *
 * Ordre de priorité verrouillé par l'opérateur humain (Gap D, Q1) :
 * cancelled > non_sourced > non_generated > draft > ordered >
 * partially_received > received. Une seule ligne annulée ne doit jamais
 * être masquée par une ligne dans un état plus avancé.
 */
class SalesOrdersTableSourcingOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->warehouse = Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $manager = User::factory()->create()->assignRole('manager');
        $manager->warehouses()->attach($this->warehouse->id);

        $this->actingAs($manager);
    }

    private function createSourcing(Product $product, string $supplierName): void
    {
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => $supplierName])->id,
            'is_active' => true,
        ]);
    }

    /**
     * Crée une ligne de vente non allouée, avec (ou sans) sourcing
     * fournisseur actif disponible. N'appelle jamais markAsConfirmed() :
     * SalesOrder::markAsConfirmed() exige qu'au moins une ligne existe
     * déjà, donc toutes les lignes d'une commande doivent être créées
     * AVANT l'unique appel à markAsConfirmed() de chaque test.
     */
    private function createItem(SalesOrder $order, ?string $supplierName = null): SalesOrderItem
    {
        $product = Product::factory()->create();

        if ($supplierName !== null) {
            $this->createSourcing($product, $supplierName);
        }

        return SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
        ]);
    }

    private function allocate(SalesOrderItem $item): SalesOrderItemAllocation
    {
        return SalesOrderItemAllocation::recordFor($item->fresh());
    }

    /*
     * =================================================================
     * 1. Commande sans aucune ligne allouée : état agrégé 'non_sourced'.
     * =================================================================
     */
    public function test_commande_sans_sourcing_agrege_en_non_sourced(): void
    {
        $order = SalesOrder::factory()->create();
        $this->createItem($order);

        $this->assertSame('non_sourced', SalesOrdersTable::sourcingOverviewState($order->fresh()));
    }

    /*
     * =================================================================
     * 2. Une seule ligne, allouée mais pas encore convertie en
     *    PurchaseOrder : état agrégé 'non_generated' (pas d'ambiguïté
     *    possible sur un singleton).
     * =================================================================
     */
    public function test_ligne_unique_allouee_sans_commande_fournisseur_agrege_en_non_generated(): void
    {
        $order = SalesOrder::factory()->create();
        $item = $this->createItem($order, 'Fournisseur Alpha');
        $order->markAsConfirmed();
        $this->allocate($item);

        $this->assertSame('non_generated', SalesOrdersTable::sourcingOverviewState($order->fresh()));
    }

    /*
     * =================================================================
     * 3. Plusieurs lignes dans le même état ('non_generated') : l'état
     *    agrégé reste cet état commun, sans ambiguïté.
     * =================================================================
     */
    public function test_plusieurs_lignes_meme_statut_agregent_vers_ce_statut_commun(): void
    {
        $order = SalesOrder::factory()->create();
        $itemA = $this->createItem($order, 'Fournisseur Alpha');
        $itemB = $this->createItem($order, 'Fournisseur Beta');
        $order->markAsConfirmed();
        $this->allocate($itemA);
        $this->allocate($itemB);

        $this->assertSame('non_generated', SalesOrdersTable::sourcingOverviewState($order->fresh()));
    }

    /*
     * =================================================================
     * 4. Plusieurs lignes avec des statuts différents ('non_sourced' et
     *    'ordered') : la priorité verrouillée impose 'non_sourced'
     *    (plus urgent que 'ordered' dans l'ordre Q1).
     * =================================================================
     */
    public function test_plusieurs_lignes_statuts_differents_applique_la_priorite(): void
    {
        $order = SalesOrder::factory()->create();
        $this->createItem($order); // reste 'non_sourced', aucun sourcing disponible
        $item = $this->createItem($order, 'Fournisseur Gamma');
        $order->markAsConfirmed();
        $allocation = $this->allocate($item);

        (new CreatePurchaseOrdersFromAllocations)->execute(
            SalesOrderItemAllocation::whereKey($allocation->id)->get()
        );
        PurchaseOrder::firstOrFail()->markAsOrdered();

        $this->assertSame('non_sourced', SalesOrdersTable::sourcingOverviewState($order->fresh()));
    }

    /*
     * =================================================================
     * 5. Achat annulé + achat actif (deux fournisseurs distincts, donc
     *    deux PurchaseOrder distincts) : le cas central de Q1 — 'cancelled'
     *    ne doit jamais être masqué par une ligne plus avancée
     *    ('received').
     * =================================================================
     */
    public function test_achat_annule_et_achat_actif_priorise_cancelled(): void
    {
        $order = SalesOrder::factory()->create();
        $itemA = $this->createItem($order, 'Fournisseur Cancel');
        $itemB = $this->createItem($order, 'Fournisseur Recu');
        $order->markAsConfirmed();
        $allocationA = $this->allocate($itemA);
        $allocationB = $this->allocate($itemB);

        (new CreatePurchaseOrdersFromAllocations)->execute(
            SalesOrderItemAllocation::query()->whereIn('id', [$allocationA->id, $allocationB->id])->get()
        );

        $cancelledPo = $itemA->fresh()->allocation->purchaseOrderItem->purchaseOrder;
        $cancelledPo->cancel();

        $receivedPo = $itemB->fresh()->allocation->purchaseOrderItem->purchaseOrder;
        $receivedPo->markAsOrdered();
        $receivedPo->receive([$receivedPo->items->first()->id => $receivedPo->items->first()->quantity_ordered]);

        $this->assertSame('cancelled', SalesOrdersTable::sourcingOverviewState($order->fresh()));
    }

    /*
     * =================================================================
     * 6. Vérification exhaustive de l'ordre de priorité Q1 lui-même,
     *    indépendamment des scénarios métier ci-dessus : pour chaque
     *    paire (état le plus prioritaire, état moins prioritaire),
     *    l'agrégat retourne toujours le plus prioritaire.
     * =================================================================
     */
    public function test_ordre_de_priorite_q1_est_strictement_respecte(): void
    {
        $priority = [
            'cancelled', 'non_sourced', 'non_generated',
            'draft', 'ordered', 'partially_received', 'received',
        ];

        foreach ($priority as $index => $expected) {
            $others = array_slice($priority, $index + 1);

            foreach ($others as $lessUrgent) {
                $order = new SalesOrder();
                $order->setRelation('items', collect([
                    (object) ['allocation' => $this->fakeAllocationFor($expected)],
                    (object) ['allocation' => $this->fakeAllocationFor($lessUrgent)],
                ]));

                $this->assertSame(
                    $expected,
                    SalesOrdersTable::sourcingOverviewState($order),
                    "Attendu '{$expected}' prioritaire sur '{$lessUrgent}'."
                );
            }
        }
    }

    private function fakeAllocationFor(string $state): ?object
    {
        if ($state === 'non_sourced') {
            return null;
        }

        if ($state === 'non_generated') {
            return (object) ['purchaseOrderItem' => null];
        }

        return (object) [
            'purchaseOrderItem' => (object) [
                'purchaseOrder' => (object) ['status' => $state],
            ],
        ];
    }

    /*
     * =================================================================
     * 7. Non-régression N+1 : la liste des commandes ne doit déclencher
     *    qu'un nombre borné de requêtes SQL, indépendant du nombre de
     *    commandes/lignes affichées, grâce à modifyQueryUsing().
     * =================================================================
     */
    public function test_liste_des_commandes_ne_provoque_pas_de_n_plus_1(): void
    {
        foreach (range(1, 5) as $i) {
            $order = SalesOrder::factory()->create();
            $item = $this->createItem($order, "Fournisseur {$i}");
            $order->markAsConfirmed();
            $this->allocate($item);
        }

        DB::enableQueryLog();

        Livewire::test(ListSalesOrders::class)->assertSuccessful();

        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        // Seuil large et documenté plutôt qu'un nombre exact fragile :
        // le but est de détecter une explosion proportionnelle au nombre
        // de commandes (N+1), pas de figer un compte précis sensible au
        // moindre changement de la table (filtres, pagination...).
        $this->assertLessThan(
            30,
            $queryCount,
            "Nombre de requêtes SQL anormalement élevé ({$queryCount}) : suspicion de N+1 sur la colonne 'sourcing_overview'."
        );
    }
}
