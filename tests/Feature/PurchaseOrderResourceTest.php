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
use App\Models\Warehouse;
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

        // Étape T11b : StockMovement::creating() (déclenché par
        // PurchaseOrder::receive()) résout désormais systématiquement
        // un entrepôt (par défaut en repli) — un entrepôt par défaut
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
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        // Étape T19 — un manager restreint doit avoir l'entrepôt effectif
        // (ici l'entrepôt par défaut créé en setUp(), implicitement
        // utilisé faute de sélection explicite) dans son périmètre.
        $user->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));

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

    /*
     * =================================================================
     * Étape T13 — sélection de l'entrepôt via l'action receiveOrder
     * =================================================================
     */

    public function test_receptionner_via_laction_avec_entrepot_explicite_enregistre_le_bon_entrepot(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t13-ui']);
        // Étape T19 — le manager doit avoir cet entrepôt dans son périmètre.
        $user->warehouses()->attach($warehouse);
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-UI-T13-1']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $component = Livewire::test(EditPurchaseOrder::class, ['record' => $order->getKey()]);
        $component->callAction('confirmOrder');

        $component->callAction('receiveOrder', data: [
            'warehouse_id' => $warehouse->id,
            'received' => [$item->id => 5],
        ]);

        $movement = \App\Models\StockMovement::first();
        $this->assertSame($warehouse->id, $movement->warehouse_id);
    }

    public function test_receptionner_via_laction_est_refuse_si_plusieurs_entrepots_actifs_sans_selection(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b-t13-ui']); // 2e entrepôt actif, en plus du défaut de setUp()
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-UI-T13-2']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);

        $component = Livewire::test(EditPurchaseOrder::class, ['record' => $order->getKey()]);
        $component->callAction('confirmOrder');

        // Le champ warehouse_id n'a plus de valeur par défaut dès que
        // 2 entrepôts actifs existent (cf. schema()) : aucune valeur
        // n'est transmise ici, simulant un envoi sans sélection.
        // L'appel ne doit PAS lever d'exception jusqu'au test (capturée
        // par le try/catch de l'action, notification d'erreur affichée
        // à la place) : on vérifie seulement qu'aucune écriture n'a eu
        // lieu.
        $component->callAction('receiveOrder', data: [
            'received' => [$item->id => 5],
        ]);

        $this->assertSame(0, \App\Models\StockMovement::count());
        $item->refresh();
        $this->assertSame(0, $item->quantity_received);
    }
}
