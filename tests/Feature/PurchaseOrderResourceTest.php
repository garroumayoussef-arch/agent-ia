<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseOrderResourceTest extends TestCase
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
        $order = PurchaseOrder::create(['reference' => 'BC-UI-1']);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $this->get(PurchaseOrderResource::getUrl('index'))->assertSuccessful();
        $this->get(PurchaseOrderResource::getUrl('create'))->assertSuccessful();
        $this->get(PurchaseOrderResource::getUrl('view', ['record' => $order]))->assertSuccessful();
        $this->get(PurchaseOrderResource::getUrl('edit', ['record' => $order]))->assertSuccessful();
    }

    /*
     * =================================================================
     * Création via le formulaire Livewire
     * =================================================================
     */

    public function test_creer_un_bon_de_commande_avec_une_ligne_via_le_formulaire(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $supplier = Supplier::create(['name' => 'AliExpress']);
        $product = $this->makeProduct();

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'reference' => 'BC-UI-2',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity_ordered' => 12,
                        'unit_price' => 9.5,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = PurchaseOrder::where('reference', 'BC-UI-2')->firstOrFail();

        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $order->status);
        $this->assertSame($supplier->id, $order->supplier_id);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame(12, $order->items()->first()->quantity_ordered);
    }

    /*
     * =================================================================
     * Actions de workflow (Confirmer / Réceptionner / Annuler)
     * =================================================================
     */

    public function test_confirmer_puis_receptionner_un_bon_via_les_actions_synchronise_le_stock(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $product = $this->makeProduct();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-UI-RECEIVE',
            'size' => 'M',
            'stock' => 3,
            'status' => 'active',
        ]);

        $order = PurchaseOrder::create(['reference' => 'BC-UI-3']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity_ordered' => 10,
        ]);

        $component = Livewire::test(EditPurchaseOrder::class, ['record' => $order->getKey()]);

        $component->callAction('confirmOrder');
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->fresh()->status);

        $component->callAction('receiveOrder', data: [
            'received' => [$item->id => 6],
        ]);

        $order->refresh();
        $item->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->status);
        $this->assertSame(6, $item->quantity_received);
        $this->assertSame(9, $variant->stock); // 3 + 6
        $this->assertSame(9, $product->stock);
    }

    public function test_annuler_un_bon_de_commande_via_laction(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-UI-4']);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        Livewire::test(EditPurchaseOrder::class, ['record' => $order->getKey()])
            ->callAction('cancelOrder');

        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_laction_receptionner_nest_pas_visible_sur_un_bon_en_brouillon(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-UI-5']);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        Livewire::test(EditPurchaseOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('receiveOrder')
            ->assertActionVisible('confirmOrder');
    }
}
