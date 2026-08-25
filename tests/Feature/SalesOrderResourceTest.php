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
use App\Models\Warehouse;
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

        // Étape T11b : StockMovement::creating() (déclenché par
        // SalesOrder::ship()) résout désormais systématiquement un
        // entrepôt (par défaut en repli) — un entrepôt par défaut
        // doit donc exister.
        Warehouse::create([
            'name' => 'Entrepôt par défaut',
            'code' => 'defaut',
            'is_default' => true,
        ]);
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
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        // Étape T19 — un manager restreint doit avoir l'entrepôt effectif
        // (ici l'entrepôt par défaut créé en setUp(), implicitement
        // utilisé faute de sélection explicite) dans son périmètre.
        $user->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));

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

    /*
     * =================================================================
     * Étape T13 — sélection de l'entrepôt via l'action shipOrder
     * =================================================================
     */

    public function test_expedier_via_laction_avec_entrepot_explicite_enregistre_le_bon_entrepot(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t13-ui']);
        // Étape T19 — le manager doit avoir cet entrepôt dans son périmètre.
        $user->warehouses()->attach($warehouse);
        $product = $this->makeProduct();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-UI-T13-SHIP',
            'stock' => 5,
            'status' => 'active',
        ]);
        \App\Models\WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 5]);

        $order = SalesOrder::create(['reference' => 'CMD-UI-T13-1']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 5,
        ]);

        $component = Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()]);
        $component->callAction('confirmOrder');

        $component->callAction('shipOrder', data: [
            'warehouse_id' => $warehouse->id,
            'shipped' => [$item->id => 5],
        ]);

        $movement = \App\Models\StockMovement::first();
        $this->assertSame($warehouse->id, $movement->warehouse_id);
    }

    public function test_expedier_via_laction_est_refuse_si_plusieurs_entrepots_actifs_sans_selection(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b-t13-ui']); // 2e entrepôt actif, en plus du défaut de setUp()
        $product = $this->makeProduct();
        $order = SalesOrder::create(['reference' => 'CMD-UI-T13-2']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $component = Livewire::test(EditSalesOrder::class, ['record' => $order->getKey()]);
        $component->callAction('confirmOrder');

        // Aucun warehouse_id transmis, alors que 2 entrepôts actifs
        // existent : ne doit provoquer aucune écriture (bloqué par la
        // validation du formulaire et/ou la garde modèle).
        $component->callAction('shipOrder', data: [
            'shipped' => [$item->id => 5],
        ]);

        $this->assertSame(0, \App\Models\StockMovement::count());
        $item->refresh();
        $this->assertSame(0, $item->quantity_shipped);
    }
}
