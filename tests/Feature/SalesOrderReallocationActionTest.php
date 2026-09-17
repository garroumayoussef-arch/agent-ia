<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Models\Product;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.9 (spécification validée) — couverture
 * de l'action Filament "reallocateSourcing" (Trait
 * HasSalesOrderReallocationAction, intégrée dans EditSalesOrder/
 * ViewSalesOrder), même gabarit exact que "allocateSourcing" (D2.5) et
 * "createPurchaseOrders" (D2.6) : visibilité liée au statut de la
 * SalesOrder, autorisation via SalesOrderResource::canEdit() (aucune
 * nouvelle permission), requiresConfirmation().
 *
 * Portée : effets OBSERVABLES en base (nouvelles allocations créées,
 * historique conservé) et visibilité/autorisation de l'action. Délègue
 * intégralement à SalesOrderItemAllocation::reallocateFor() (D2.9,
 * couvert en détail par SalesOrderItemAllocationTest) : aucune règle de
 * sélection fournisseur ni de calcul de retour intégral n'est retestée
 * ici au niveau service, uniquement leur orchestration Filament.
 */
class SalesOrderReallocationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);
    }

    private function actingAsManager(): User
    {
        $manager = User::factory()->create()->assignRole('manager');
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));
        $this->actingAs($manager);

        return $manager;
    }

    private function createSourcing(Product $product, array $overrides = []): void
    {
        $product->supplierSourcings()->create(array_merge([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Commande confirmée, une ligne dont l'allocation a été
     * intégralement reçue puis intégralement retournée au fournisseur —
     * éligible à la ré-allocation. $withAlternative contrôle la présence
     * d'un second fournisseur actif compatible.
     *
     * @return array{0: SalesOrder, 1: SalesOrderItem, 2: SalesOrderItemAllocation}
     */
    private function createOrderWithFullyReturnedAllocation(int $quantity = 5, bool $withAlternative = true): array
    {
        $product = Product::factory()->create();
        $this->createSourcing($product);

        if ($withAlternative) {
            $this->createSourcing($product);
        }

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => $quantity,
        ]);
        $order->markAsConfirmed();

        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());

        $purchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]))['created'][0];
        $purchaseOrder->markAsOrdered();
        $purchaseOrderItem = $purchaseOrder->items()->first();
        $purchaseOrder->fresh()->receive([$purchaseOrderItem->id => $quantity]);
        PurchaseOrderItemReturn::recordFor($purchaseOrderItem->fresh(), $quantity, now()->toDateString());

        return [$order->fresh(), $item->fresh(), $allocation->fresh()];
    }

    /*
     * =================================================================
     * 1. Ligne éligible (retour intégral) + fournisseur alternatif
     * disponible : ré-allocation effective
     * =================================================================
     */
    public function test_ligne_eligible_avec_alternative_est_reallouee(): void
    {
        $this->actingAsManager();

        [$order, $item, $ancienneAllocation] = $this->createOrderWithFullyReturnedAllocation();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('reallocateSourcing');

        $item->refresh();
        $this->assertSame(2, SalesOrderItemAllocation::count());
        $this->assertNotSame($ancienneAllocation->id, $item->allocation->id);
        $this->assertSame($ancienneAllocation->id, $item->allocation->replaces_allocation_id);
    }

    /*
     * =================================================================
     * 2. Ligne éligible mais AUCUN fournisseur alternatif : aucune
     * écriture, ancienne allocation inchangée
     * =================================================================
     */
    public function test_ligne_eligible_sans_alternative_ne_cree_aucune_allocation(): void
    {
        $this->actingAsManager();

        [$order, $item, $ancienneAllocation] = $this->createOrderWithFullyReturnedAllocation(withAlternative: false);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('reallocateSourcing');

        $this->assertSame(1, SalesOrderItemAllocation::count());
        $this->assertSame($ancienneAllocation->id, $item->fresh()->allocation->id);
    }

    /*
     * =================================================================
     * 3. Ligne non éligible (retour partiel) parmi d'autres : ne bloque
     * pas le traitement des lignes éligibles
     * =================================================================
     */
    public function test_ligne_avec_retour_partiel_nest_pas_reallouee(): void
    {
        $this->actingAsManager();

        $product = Product::factory()->create();
        $this->createSourcing($product);

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 10,
        ]);
        $order->markAsConfirmed();

        $allocation = SalesOrderItemAllocation::recordFor($item->fresh());
        $purchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]))['created'][0];
        $purchaseOrder->markAsOrdered();
        $purchaseOrderItem = $purchaseOrder->items()->first();
        $purchaseOrder->fresh()->receive([$purchaseOrderItem->id => 10]);
        PurchaseOrderItemReturn::recordFor($purchaseOrderItem->fresh(), 4, now()->toDateString());

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('reallocateSourcing');

        $this->assertSame(1, SalesOrderItemAllocation::count());
        $this->assertSame($allocation->id, $item->fresh()->allocation->id);
    }

    /*
     * =================================================================
     * 4. Ligne jamais allouée : ignorée silencieusement, pas de crash
     * =================================================================
     */
    public function test_ligne_sans_allocation_est_ignoree(): void
    {
        $this->actingAsManager();

        $order = SalesOrder::factory()->create();
        SalesOrderItem::factory()->create(['sales_order_id' => $order->id]);
        $order->markAsConfirmed();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('reallocateSourcing');

        $this->assertSame(0, SalesOrderItemAllocation::count());
    }

    /*
     * =================================================================
     * 5. Rejouer l'action après une ré-allocation réussie : pas de
     * double ré-allocation (idempotence de l'action groupée)
     * =================================================================
     */
    public function test_rejouer_laction_apres_reallocation_reussie_ne_cree_pas_de_doublon(): void
    {
        $this->actingAsManager();

        [$order, $item] = $this->createOrderWithFullyReturnedAllocation();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('reallocateSourcing');
        $countApresPremierAppel = SalesOrderItemAllocation::count();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('reallocateSourcing');

        $this->assertSame($countApresPremierAppel, SalesOrderItemAllocation::count());
    }

    /*
     * =================================================================
     * 6-8. Statuts pour lesquels l'action doit rester invisible — même
     * gabarit qu'allocateSourcing/createPurchaseOrders
     * =================================================================
     */
    public function test_commande_draft_action_invisible(): void
    {
        $this->actingAsManager();

        $order = SalesOrder::factory()->create();
        SalesOrderItem::factory()->create(['sales_order_id' => $order->id]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('reallocateSourcing');
    }

    public function test_commande_cancelled_action_invisible(): void
    {
        $this->actingAsManager();

        $order = SalesOrder::factory()->create();
        SalesOrderItem::factory()->create(['sales_order_id' => $order->id]);
        $order->markAsConfirmed();
        $order->fresh()->cancel();

        Livewire::test(EditSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertActionHidden('reallocateSourcing');
    }

    /*
     * =================================================================
     * 9-10. Autorisation — visibilité liée uniquement au statut (même
     * décision que D2.5.3/D2.6.3, Option A) ; blocage réel vérifié sur
     * appel direct forgé par un viewer
     * =================================================================
     */
    public function test_viewer_voit_laction_mais_ne_peut_pas_lexecuter(): void
    {
        $this->actingAsManager();
        [$order] = $this->createOrderWithFullyReturnedAllocation();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertActionVisible('reallocateSourcing');
    }

    public function test_viewer_ne_peut_pas_declencher_laction_par_appel_direct(): void
    {
        $this->actingAsManager();
        [$order, $item, $ancienneAllocation] = $this->createOrderWithFullyReturnedAllocation();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->call('mountAction', 'reallocateSourcing');

        $this->assertSame(1, SalesOrderItemAllocation::count());
        $this->assertSame($ancienneAllocation->id, $item->fresh()->allocation->id);
    }

    /*
     * =================================================================
     * 11. Absence d'effet de bord — aucun StockMovement/PurchaseOrder
     * supplémentaire généré par l'action elle-même, statut de la
     * commande inchangé
     * =================================================================
     */
    public function test_aucun_effet_de_bord_sur_stock_ou_statut_de_commande(): void
    {
        $this->actingAsManager();

        [$order] = $this->createOrderWithFullyReturnedAllocation();
        $statutAvant = $order->status;
        $stockMovementsAvant = \App\Models\StockMovement::count();
        $purchaseOrdersAvant = \App\Models\PurchaseOrder::count();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('reallocateSourcing');

        $this->assertSame($stockMovementsAvant, \App\Models\StockMovement::count());
        $this->assertSame($purchaseOrdersAvant, \App\Models\PurchaseOrder::count());
        $this->assertSame($statutAvant, $order->fresh()->status);
    }

    /*
     * =================================================================
     * 12. Transversalité — comportement identique pour plusieurs
     * activités utilisant Product
     * =================================================================
     */
    public function test_le_comportement_est_identique_pour_plusieurs_activites_utilisant_product(): void
    {
        $this->actingAsManager();

        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            $product = Product::factory()->create(['activity' => $activity]);
            $this->createSourcing($product);
            $this->createSourcing($product);

            $order = SalesOrder::factory()->create();
            $item = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id]);
            $order->markAsConfirmed();

            $allocation = SalesOrderItemAllocation::recordFor($item->fresh());
            $purchaseOrder = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]))['created'][0];
            $purchaseOrder->markAsOrdered();
            $purchaseOrderItem = $purchaseOrder->items()->first();
            $purchaseOrder->fresh()->receive([$purchaseOrderItem->id => $item->quantity_ordered]);
            PurchaseOrderItemReturn::recordFor($purchaseOrderItem->fresh(), $item->quantity_ordered, now()->toDateString());

            Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
                ->callAction('reallocateSourcing');

            $this->assertNotSame($allocation->id, $item->fresh()->allocation->id);
        }
    }
}
