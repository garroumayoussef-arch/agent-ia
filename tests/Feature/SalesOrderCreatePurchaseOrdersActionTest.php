<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier Dropshipping, étape D2.6.1 — test de caractérisation de la
 * future action Filament "createPurchaseOrders" (D2.6.4 : intégration
 * dans EditSalesOrder/ViewSalesOrder), ÉCRIT AVANT TOUT CODE D2.6.2/
 * D2.6.3/D2.6.4 — même discipline que SalesOrderSourcingActionTest
 * (D2.5.1).
 *
 * L'action "createPurchaseOrders" n'existe sur AUCUNE page à ce stade,
 * et CreatePurchaseOrdersFromAllocations (D2.6.3) n'existe pas non plus :
 * ce fichier est donc intégralement ROUGE tant que D2.6.2/D2.6.3/D2.6.4
 * n'ont pas été réalisés. Portée limitée aux effets observables
 * (visibilité, autorisation, absence d'effet de bord) — le contenu
 * exact de la notification de synthèse n'est pas figé ici, même
 * convention que SalesOrderSourcingActionTest.
 */
class SalesOrderCreatePurchaseOrdersActionTest extends TestCase
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
        $this->actingAs($manager);

        return $manager;
    }

    private function createSourcing(Product $product): void
    {
        $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'is_active' => true,
        ]);
    }

    private function createConfirmedOrderWithAllocatedItem(): SalesOrder
    {
        $product = Product::factory()->create();
        $this->createSourcing($product);

        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id]);
        $order->markAsConfirmed();
        SalesOrderItemAllocation::recordFor($item->fresh());

        return $order->fresh();
    }

    /*
     * =================================================================
     * 8. Visible sur commande CONFIRMED
     * =================================================================
     */
    public function test_action_visible_sur_commande_confirmee(): void
    {
        $this->actingAsManager();
        $order = $this->createConfirmedOrderWithAllocatedItem();

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('createPurchaseOrders');
    }

    /*
     * =================================================================
     * 9. Invisible sur DRAFT / CANCELLED / SHIPPED
     * =================================================================
     */
    public function test_action_invisible_sur_commande_draft(): void
    {
        $this->actingAsManager();
        $order = SalesOrder::factory()->create();
        SalesOrderItem::factory()->create(['sales_order_id' => $order->id]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('createPurchaseOrders');
    }

    public function test_action_invisible_sur_commande_cancelled(): void
    {
        $this->actingAsManager();
        $order = $this->createConfirmedOrderWithAllocatedItem();
        $order->cancel();

        Livewire::test(EditSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertActionHidden('createPurchaseOrders');
    }

    public function test_action_invisible_sur_commande_shipped(): void
    {
        $manager = $this->actingAsManager();
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));

        $product = Product::factory()->create(['stock' => 100]);
        $this->createSourcing($product);
        $order = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id]);
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => $item->quantity_ordered]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->assertActionHidden('createPurchaseOrders');
    }

    /*
     * =================================================================
     * 10. Viewer - visible (statut seul, meme pattern que D2.5.3
     * Option A) mais execution reellement bloquee par ->authorize()
     * =================================================================
     */
    public function test_viewer_voit_laction_mais_ne_peut_pas_lexecuter(): void
    {
        $this->actingAsManager();
        $order = $this->createConfirmedOrderWithAllocatedItem();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('createPurchaseOrders');

        Livewire::test(ViewSalesOrder::class, ['record' => $order->getKey()])
            ->call('mountAction', 'createPurchaseOrders');

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    /*
     * =================================================================
     * 11. Transversalite - comportement identique pour plusieurs
     * activites, aucune branche liee a `activity`
     * =================================================================
     */
    public function test_le_comportement_est_identique_pour_plusieurs_activites(): void
    {
        $this->actingAsManager();

        $order = SalesOrder::factory()->create();

        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            $product = Product::factory()->create(['activity' => $activity]);
            $this->createSourcing($product);
            SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id]);
        }

        $order->markAsConfirmed();

        foreach ($order->fresh()->items as $item) {
            SalesOrderItemAllocation::recordFor($item);
        }

        Livewire::test(EditSalesOrder::class, ['record' => $order->fresh()->getKey()])
            ->callAction('createPurchaseOrders');

        $this->assertSame(4, PurchaseOrder::count());
    }
}
