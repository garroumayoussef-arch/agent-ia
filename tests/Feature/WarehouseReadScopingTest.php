<?php

namespace Tests\Feature;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Pages\ViewStockMovement;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\WarehouseStocks\Pages\ListWarehouseStocks;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Filament\Widgets\LowStockAlertByWarehouse;
use App\Filament\Widgets\WarehouseStockOverview;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\RoleSeeder;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T20 — scoping en LECTURE par entrepôt (managers uniquement,
 * D1), via WarehouseStockResource/StockMovementResource::
 * getEloquentQuery() et le scoping direct des widgets T15/T18. Ne
 * couvre pas le scoping en ÉCRITURE (T19, déjà testé par
 * WarehousePermissionScopingTest.php) ni la logique métier T12/T13,
 * inchangées.
 */
class WarehouseReadScopingTest extends TestCase
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
            'nom' => 'Produit T20',
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
     * WarehouseStockResource (T14)
     * =================================================================
     */

    public function test_un_admin_voit_toutes_les_lignes_warehouse_stock_sans_etre_attribue(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $line = WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'stock' => 5]);

        Livewire::test(ListWarehouseStocks::class)
            ->assertCanSeeTableRecords([$line]);
    }

    public function test_un_manager_ne_voit_que_les_lignes_warehouse_stock_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $product = $this->makeProduct();
        $lineAllowed = WarehouseStock::create(['warehouse_id' => $warehouseAllowed->id, 'product_id' => $product->id, 'stock' => 5]);
        $lineForbidden = WarehouseStock::create(['warehouse_id' => $warehouseForbidden->id, 'product_id' => $product->id, 'stock' => 8]);

        Livewire::test(ListWarehouseStocks::class)
            ->assertCanSeeTableRecords([$lineAllowed])
            ->assertCanNotSeeTableRecords([$lineForbidden]);
    }

    public function test_un_manager_sans_entrepot_attribue_ne_voit_aucune_ligne_warehouse_stock(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'stock' => 5]);

        Livewire::test(ListWarehouseStocks::class)
            ->assertCountTableRecords(0);
    }

    /*
     * =================================================================
     * StockMovementResource (T16)
     * =================================================================
     */

    public function test_un_manager_ne_voit_que_les_mouvements_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $product = $this->makeProduct();
        $movementAllowed = StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouseAllowed->id, 'type' => 'purchase', 'quantity' => 5]);
        $movementForbidden = StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouseForbidden->id, 'type' => 'purchase', 'quantity' => 3]);

        Livewire::test(ListStockMovements::class)
            ->assertCanSeeTableRecords([$movementAllowed])
            ->assertCanNotSeeTableRecords([$movementForbidden]);
    }

    public function test_un_manager_sans_entrepot_attribue_ne_voit_aucun_mouvement(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 5]);

        Livewire::test(ListStockMovements::class)
            ->assertCountTableRecords(0);
    }

    public function test_un_acces_direct_par_url_a_un_mouvement_hors_perimetre_renvoie_404(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $product = $this->makeProduct();
        $movementForbidden = StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouseForbidden->id, 'type' => 'purchase', 'quantity' => 3]);

        $this->get(StockMovementResource::getUrl('view', ['record' => $movementForbidden]))
            ->assertNotFound();
    }

    public function test_un_acces_direct_par_url_a_un_mouvement_du_perimetre_reste_accessible(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouse = $this->makeWarehouse();
        $user->warehouses()->attach($warehouse);

        $product = $this->makeProduct();
        $movement = StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 3]);

        $this->get(StockMovementResource::getUrl('view', ['record' => $movement]))
            ->assertSuccessful();
    }

    /*
     * =================================================================
     * D3 — transferts : chaque jambe (transfer_out/transfer_in) scopée
     * indépendamment selon son PROPRE warehouse_id.
     * =================================================================
     */

    public function test_un_manager_attribue_uniquement_a_lentrepot_source_ne_voit_que_la_jambe_sortante(): void
    {
        $warehouseSource = $this->makeWarehouse();
        $warehouseDestination = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 10]);
        WarehouseStock::create(['warehouse_id' => $warehouseSource->id, 'product_id' => $product->id, 'stock' => 10]);

        // Le transfert est exécuté par un admin (T19 exige les deux
        // entrepôts dans le périmètre de son auteur) : seul le SCOPING
        // EN LECTURE (T20) du manager ci-dessous est ce qui est testé
        // ici, pas l'autorisation d'écriture T19.
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $transfer = StockTransfer::execute([
            'from_warehouse_id' => $warehouseSource->id,
            'to_warehouse_id' => $warehouseDestination->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);

        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);
        $user->warehouses()->attach($warehouseSource);

        $transferOut = $transfer->stockMovements()->where('type', 'transfer_out')->firstOrFail();
        $transferIn = $transfer->stockMovements()->where('type', 'transfer_in')->firstOrFail();

        Livewire::test(ListStockMovements::class)
            ->assertCanSeeTableRecords([$transferOut])
            ->assertCanNotSeeTableRecords([$transferIn]);

        $this->get(StockMovementResource::getUrl('view', ['record' => $transferIn]))
            ->assertNotFound();
    }

    public function test_un_manager_attribue_uniquement_a_lentrepot_destination_ne_voit_que_la_jambe_entrante(): void
    {
        $warehouseSource = $this->makeWarehouse();
        $warehouseDestination = $this->makeWarehouse();
        $product = $this->makeProduct(['stock' => 10]);
        WarehouseStock::create(['warehouse_id' => $warehouseSource->id, 'product_id' => $product->id, 'stock' => 10]);

        // Le transfert est exécuté par un admin (T19 exige les deux
        // entrepôts dans le périmètre de son auteur) : seul le SCOPING
        // EN LECTURE (T20) du manager ci-dessous est ce qui est testé
        // ici, pas l'autorisation d'écriture T19.
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $transfer = StockTransfer::execute([
            'from_warehouse_id' => $warehouseSource->id,
            'to_warehouse_id' => $warehouseDestination->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);

        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);
        $user->warehouses()->attach($warehouseDestination);

        $transferOut = $transfer->stockMovements()->where('type', 'transfer_out')->firstOrFail();
        $transferIn = $transfer->stockMovements()->where('type', 'transfer_in')->firstOrFail();

        Livewire::test(ListStockMovements::class)
            ->assertCanSeeTableRecords([$transferIn])
            ->assertCanNotSeeTableRecords([$transferOut]);
    }

    /*
     * =================================================================
     * Widgets T15/T18
     * =================================================================
     */

    public function test_lalerte_stock_bas_par_entrepot_ne_montre_que_le_perimetre_du_manager(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $productAllowed = $this->makeProduct(['reference' => 'REF-ALLOWED']);
        $productForbidden = $this->makeProduct(['reference' => 'REF-FORBIDDEN']);
        $lineAllowed = WarehouseStock::create(['warehouse_id' => $warehouseAllowed->id, 'product_id' => $productAllowed->id, 'stock' => 1]);
        WarehouseStock::create(['warehouse_id' => $warehouseForbidden->id, 'product_id' => $productForbidden->id, 'stock' => 1]);

        $widget = new LowStockAlertByWarehouse();
        $results = $widget->table(Table::make($widget))->getQuery()->get();

        $this->assertCount(1, $results);
        $this->assertSame($lineAllowed->id, $results->first()->id);
    }

    public function test_le_reporting_consolide_ne_montre_que_les_entrepots_du_perimetre_du_manager(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $widget = new WarehouseStockOverview();
        $results = $widget->table(Table::make($widget))->getQuery()->get();

        $this->assertCount(1, $results);
        $this->assertSame($warehouseAllowed->id, $results->first()->id);
    }

    public function test_un_admin_voit_tous_les_entrepots_dans_les_deux_widgets_sans_etre_attribue(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct();
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 1]);
        WarehouseStock::create(['warehouse_id' => $warehouseB->id, 'product_id' => $product->id, 'stock' => 1]);

        $lowStockWidget = new LowStockAlertByWarehouse();
        $overviewWidget = new WarehouseStockOverview();

        $this->assertCount(2, $overviewWidget->table(Table::make($overviewWidget))->getQuery()->get());
        $this->assertCount(2, $lowStockWidget->table(Table::make($lowStockWidget))->getQuery()->get());
    }

    /*
     * =================================================================
     * D4 — WarehouseResource (T10) reste hors périmètre du scoping
     * =================================================================
     */

    public function test_un_manager_scope_voit_la_liste_complete_des_entrepots(): void
    {
        $user = User::factory()->create()->assignRole('manager');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $this->get(WarehouseResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($warehouseAllowed->name)
            ->assertSee($warehouseForbidden->name);
    }

    /*
     * =================================================================
     * D1 — viewer inchangé
     * =================================================================
     */

    public function test_un_viewer_voit_toutes_les_lignes_warehouse_stock_et_mouvements_sans_etre_attribue(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $line = WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'stock' => 5]);
        $movement = StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 5]);

        Livewire::test(ListWarehouseStocks::class)->assertCanSeeTableRecords([$line]);
        Livewire::test(ListStockMovements::class)->assertCanSeeTableRecords([$movement]);
        $this->get(StockMovementResource::getUrl('view', ['record' => $movement]))->assertSuccessful();
    }
}
