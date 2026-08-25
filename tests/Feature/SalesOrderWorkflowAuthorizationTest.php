<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T25-C — confirmOrderAction/cancelOrderAction/shipOrderAction
 * (SalesOrder) n'avaient aucune garde de rôle en ->visible() (statut
 * seul) : leur seule protection venait de ce que EditSalesOrder n'est
 * atteignable que par un admin/manager, alors que ViewSalesOrder (qui
 * compose pourtant le même trait) reste accessible à un viewer.
 *
 * Ces tests appellent directement mountAction()/callMountedAction() —
 * pas le helper de test callAction(), qui pré-vérifie lui-même
 * assertActionVisible() et ne testerait donc jamais le contournement
 * réel — pour reproduire exactement un appel Livewire direct/forgé,
 * indépendant de ce que l'interface affiche.
 */
class SalesOrderWorkflowAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);
    }

    private function makeDraftOrderWithItem(): array
    {
        $customer = Customer::create([
            'name' => 'Client Autorisation',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Autorisation',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 2,
            'unit_price' => 20,
        ]);

        return [$order, $item];
    }

    public function test_un_viewer_ne_peut_pas_confirmer_une_commande_par_appel_direct_de_laction(): void
    {
        [$order] = $this->makeDraftOrderWithItem();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(\App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder::class, ['record' => $order->getKey()])
            ->call('mountAction', 'confirmOrder');

        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->fresh()->status);
    }

    public function test_un_viewer_ne_peut_pas_annuler_une_commande_par_appel_direct_de_laction(): void
    {
        [$order] = $this->makeDraftOrderWithItem();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(\App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder::class, ['record' => $order->getKey()])
            ->call('mountAction', 'cancelOrder');

        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->fresh()->status);
    }

    public function test_un_viewer_ne_peut_pas_expedier_une_commande_par_appel_direct_de_laction(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        [$order, $item] = $this->makeDraftOrderWithItem();
        $order->markAsConfirmed();

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(\App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder::class, ['record' => $order->getKey()])
            ->call('mountAction', 'shipOrder');

        $order->refresh();
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->status);
        $this->assertSame(0, $item->fresh()->quantity_shipped);
    }

    /**
     * Contrôle de non-régression (règle 7) — un manager doit pouvoir
     * confirmer une commande exactement comme avant l'ajout de la garde
     * ->authorize().
     */
    public function test_un_manager_peut_toujours_confirmer_une_commande_via_laction(): void
    {
        [$order] = $this->makeDraftOrderWithItem();

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(\App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder::class, ['record' => $order->getKey()])
            ->call('mountAction', 'confirmOrder')
            ->call('callMountedAction');

        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->fresh()->status);
    }
}
