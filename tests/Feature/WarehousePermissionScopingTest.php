<?php

namespace Tests\Feature;

use App\Filament\Resources\StockMovements\Pages\CreateStockMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T19 — permissions par entrepôt (décisions D1-D6). Couvre le
 * comportement d'autorisation ajouté sur les 4 points d'exécution :
 * PurchaseOrder::receive(), SalesOrder::ship(), StockTransfer::execute()
 * et CreateStockMovement (gap T16 comblé, D5).
 *
 * Ne couvre PAS la logique métier T12/T13 elle-même (quantités,
 * ambiguïté, indivisibilité...), déjà testée par
 * PurchaseOrderTest/SalesOrderTest/StockTransferTest, inchangée.
 */
class WarehousePermissionScopingTest extends TestCase
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
            'nom' => 'Produit T19',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    private function makeWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'name' => 'Entrepôt '.uniqid(),
            'code' => 'w-'.uniqid(),
        ], $attributes));
    }

    /*
     * =================================================================
     * PurchaseOrder::receive()
     * =================================================================
     */

    public function test_un_admin_peut_receptionner_dans_nimporte_quel_entrepot(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-T19-1']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();

        $order->receive([$item->id => 5], $warehouse->id);

        $this->assertSame(1, StockMovement::count());
    }

    public function test_un_manager_sans_entrepot_attribue_ne_peut_pas_receptionner(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-T19-2']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();

        $this->expectException(\Exception::class);

        try {
            $order->receive([$item->id => 5], $warehouse->id);
        } finally {
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_un_manager_ne_peut_pas_receptionner_hors_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-T19-3']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();

        $this->expectException(\Exception::class);

        try {
            $order->receive([$item->id => 5], $warehouseForbidden->id);
        } finally {
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_un_manager_peut_receptionner_dans_un_entrepot_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = $this->makeWarehouse();
        $user->warehouses()->attach($warehouse);

        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-T19-4']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();

        $order->receive([$item->id => 5], $warehouse->id);

        $this->assertSame(1, StockMovement::count());
        $this->assertSame($warehouse->id, StockMovement::first()->warehouse_id);
    }

    /*
     * =================================================================
     * SalesOrder::ship()
     * =================================================================
     */

    public function test_un_manager_ne_peut_pas_expedier_hors_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $product = $this->makeProduct(['stock' => 10]);
        WarehouseStock::create(['warehouse_id' => $warehouseForbidden->id, 'product_id' => $product->id, 'stock' => 10]);

        $order = SalesOrder::create(['reference' => 'CMD-T19-1']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsConfirmed();

        $this->expectException(\Exception::class);

        try {
            $order->ship([$item->id => 5], $warehouseForbidden->id);
        } finally {
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_un_manager_peut_expedier_depuis_un_entrepot_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = $this->makeWarehouse();
        $user->warehouses()->attach($warehouse);

        $product = $this->makeProduct(['stock' => 10]);
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'stock' => 10]);

        $order = SalesOrder::create(['reference' => 'CMD-T19-2']);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsConfirmed();

        $order->ship([$item->id => 5], $warehouse->id);

        $this->assertSame(1, StockMovement::count());
    }

    /*
     * =================================================================
     * StockTransfer::execute() — D4 : source ET destination requises
     * =================================================================
     */

    public function test_un_manager_attribue_a_un_seul_des_deux_entrepots_ne_peut_pas_transferer(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        // Attribué UNIQUEMENT à la source : D4 exige les deux.
        $user->warehouses()->attach($warehouseA);

        $product = $this->makeProduct(['stock' => 10]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 10]);

        $this->expectException(\Exception::class);

        try {
            StockTransfer::execute([
                'from_warehouse_id' => $warehouseA->id,
                'to_warehouse_id' => $warehouseB->id,
                'product_id' => $product->id,
                'quantity' => 3,
            ]);
        } finally {
            $this->assertSame(0, StockTransfer::count());
        }
    }

    public function test_un_manager_attribue_aux_deux_entrepots_peut_transferer(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);

        $product = $this->makeProduct(['stock' => 10]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 10]);

        StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $this->assertSame(1, StockTransfer::count());
    }

    public function test_un_admin_peut_transferer_sans_etre_attribue_a_aucun_entrepot(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 10]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 10]);

        StockTransfer::execute([
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $this->assertSame(1, StockTransfer::count());
    }

    /*
     * =================================================================
     * CreateStockMovement (D5 — gap T16 comblé)
     * =================================================================
     */

    public function test_un_manager_ne_peut_pas_creer_un_mouvement_hors_de_son_perimetre_meme_envoye_directement(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $product = $this->makeProduct();

        // Simule un navigateur qui contournerait le filtrage des options
        // du Select (StockMovementForm) en envoyant directement un
        // warehouse_id hors périmètre : la barrière autoritaire reste
        // CreateStockMovement::mutateFormDataBeforeCreate().
        Livewire::test(CreateStockMovement::class)
            ->fillForm([
                'product_id' => $product->id,
                'warehouse_id' => $warehouseForbidden->id,
                'type' => 'purchase',
                'quantity' => 5,
            ])
            ->call('create');

        $this->assertSame(0, StockMovement::count());
    }

    public function test_un_manager_peut_creer_un_mouvement_dans_un_entrepot_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = $this->makeWarehouse();
        $user->warehouses()->attach($warehouse);

        $product = $this->makeProduct();

        Livewire::test(CreateStockMovement::class)
            ->fillForm([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'type' => 'purchase',
                'quantity' => 5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, StockMovement::count());
        $this->assertSame($warehouse->id, StockMovement::first()->warehouse_id);
    }

    /*
     * =================================================================
     * D3 — comportement inchangé hors du rôle manager
     * =================================================================
     */

    public function test_labsence_dutilisateur_authentifie_nest_pas_restreinte_contexte_systeme(): void
    {
        // Aucun actingAs() : simule un contexte système/CLI/job/seeder,
        // jamais restreint par T19 — comportement T10-T18 inchangé.
        $warehouse = $this->makeWarehouse(['is_default' => true]);
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-T19-SYS']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();

        $order->receive([$item->id => 5], $warehouse->id);

        $this->assertSame(1, StockMovement::count());
    }

    public function test_un_utilisateur_sans_role_manager_nest_pas_restreint_par_ce_controle(): void
    {
        // D3 — le rattachement warehouse_user ne concerne que les
        // managers en T19 : un utilisateur authentifié sans rôle
        // manager (ici sans aucun rôle) ne doit pas être bloqué par
        // ScopesToOwnWarehouses, même s'il n'est attribué à aucun
        // entrepôt. (En pratique, HasRoleBasedAuthorization bloque déjà
        // ce profil AVANT d'atteindre ce point via l'UI Filament — ce
        // test verrouille le comportement du contrôle lui-même.)
        $this->actingAs(User::factory()->create());

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $order = PurchaseOrder::create(['reference' => 'BC-T19-VIEWER']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 5,
        ]);
        $order->markAsOrdered();

        $order->receive([$item->id => 5], $warehouse->id);

        $this->assertSame(1, StockMovement::count());
    }
}
