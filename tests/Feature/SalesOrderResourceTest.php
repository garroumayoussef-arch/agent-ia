<?php

namespace Tests\Feature;

use App\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesOrderResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Test',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    /*
     * =================================================================
     * Accessibilité des pages
     * =================================================================
     */

    public function test_les_pages_de_la_ressource_sont_accessibles(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-UI-1']);
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $this->get(SalesOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('create'))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('view', ['record' => $order]))->assertSuccessful();
        $this->get(SalesOrderResource::getUrl('edit', ['record' => $order]))->assertSuccessful();
    }

    public function test_les_pages_customer_sont_accessibles(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(\App\Filament\Resources\Customers\CustomerResource::getUrl('index'))->assertSuccessful();
        $this->get(\App\Filament\Resources\Customers\CustomerResource::getUrl('create'))->assertSuccessful();
    }

    /*
     * =================================================================
     * Création via le formulaire Livewire
     * =================================================================
     */

    public function test_creer_une_commande_avec_une_ligne_via_le_formulaire(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $customer = Customer::create(['name' => 'Jean Dupont']);
        $product = $this->makeProduct();

        Livewire::test(CreateSalesOrder::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'reference' => 'CMD-UI-2',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity_ordered' => 3,
                        'unit_price' => 25,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = SalesOrder::where('reference', 'CMD-UI-2')->firstOrFail();

        $this->assertSame(SalesOrder::STATUS_DRAFT, $order->status);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame(3, $order->items()->first()->quantity_ordered);
    }

    /*
     * =================================================================
     * Actions de workflow (Confirmer / Expédier / Annuler)
     * =================================================================
     */

    public function test_confirmer_puis_expedier_une_commande_via_les_actions_decremente_le_stock(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $product = $this->makeProduct();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-UI-SHIP',
            'size' => 'M',
            'stock' => 15,
            'status' => 'active',
        ]);

        $order = SalesOrder::create(['reference' => 'CMD-UI-3']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);

        $component = Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()]);

        $component->callAction('confirmOrder');
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $order->fresh()->status);

        $component->callAction('shipOrder', data: [
            'shipped' => [$item->id => 6],
        ]);

        $order->refresh();
        $item->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(SalesOrder::STATUS_PARTIALLY_SHIPPED, $order->status);
        $this->assertSame(6, $item->quantity_shipped);
        $this->assertSame(9, $variant->stock); // 15 - 6
        $this->assertSame(9, $product->stock);
    }

    public function test_annuler_une_commande_via_laction(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-UI-4']);
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->callAction('cancelOrder');

        $this->assertSame(SalesOrder::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_laction_expedier_nest_pas_visible_sur_une_commande_en_brouillon(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-UI-5']);
        SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('shipOrder')
            ->assertActionVisible('confirmOrder');
    }
}
