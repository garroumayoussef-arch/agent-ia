<?php

namespace Tests\Feature;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\WarehouseStocks\Pages\ListWarehouseStocks;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Filament\Widgets\LowStockAlertByWarehouse;
use App\Filament\Widgets\WarehouseStockOverview;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\RoleSeeder;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T21 — scoping en LECTURE étendu au rôle viewer (décisions
 * D1-D5), via la nouvelle méthode currentUserReadWarehouseIds()
 * (ScopesToOwnWarehouses), séparée de currentUserWarehouseIds() (T19,
 * écriture, jamais modifiée). Couvre exclusivement le comportement
 * viewer : le comportement manager/admin est déjà couvert par
 * WarehouseReadScopingTest.php (T20) et n'est pas modifié par T21 (voir
 * son test de non-régression dédié).
 */
class ViewerReadScopingTest extends TestCase
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
            'nom' => 'Produit T21',
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
     * D1 — fail-closed : viewer sans entrepôt attribué
     * =================================================================
     */

    public function test_un_viewer_sans_entrepot_attribue_ne_voit_aucune_ligne_warehouse_stock(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'stock' => 5]);

        Livewire::test(ListWarehouseStocks::class)
            ->assertCountTableRecords(0);
    }

    public function test_un_viewer_sans_entrepot_attribue_ne_voit_aucun_mouvement(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 5]);

        Livewire::test(ListStockMovements::class)
            ->assertCountTableRecords(0);
    }

    /*
     * =================================================================
     * Viewer avec un entrepôt attribué
     * =================================================================
     */

    public function test_un_viewer_avec_un_entrepot_attribue_ne_voit_que_les_lignes_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('viewer');
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

    public function test_un_viewer_avec_un_entrepot_attribue_ne_voit_que_les_mouvements_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('viewer');
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

    /*
     * =================================================================
     * Viewer avec PLUSIEURS entrepôts attribués
     * =================================================================
     */

    public function test_un_viewer_avec_plusieurs_entrepots_attribues_voit_lunion_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('viewer');
        $this->actingAs($user);

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach([$warehouseA->id, $warehouseB->id]);

        $product = $this->makeProduct();
        $lineA = WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);
        $lineB = WarehouseStock::create(['warehouse_id' => $warehouseB->id, 'product_id' => $product->id, 'stock' => 6]);
        $lineForbidden = WarehouseStock::create(['warehouse_id' => $warehouseForbidden->id, 'product_id' => $product->id, 'stock' => 7]);

        Livewire::test(ListWarehouseStocks::class)
            ->assertCanSeeTableRecords([$lineA, $lineB])
            ->assertCanNotSeeTableRecords([$lineForbidden]);
    }

    /*
     * =================================================================
     * Accès direct par URL
     * =================================================================
     */

    public function test_un_viewer_ne_peut_pas_acceder_par_url_directe_a_un_mouvement_hors_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('viewer');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $warehouseForbidden = $this->makeWarehouse();
        $user->warehouses()->attach($warehouseAllowed);

        $product = $this->makeProduct();
        $movementForbidden = StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouseForbidden->id, 'type' => 'purchase', 'quantity' => 3]);

        $this->get(StockMovementResource::getUrl('view', ['record' => $movementForbidden]))
            ->assertNotFound();
    }

    public function test_un_viewer_peut_acceder_par_url_directe_a_un_mouvement_de_son_perimetre(): void
    {
        $user = User::factory()->create()->assignRole('viewer');
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
     * Widgets T15/T18
     * =================================================================
     */

    public function test_lalerte_stock_bas_par_entrepot_respecte_le_perimetre_du_viewer(): void
    {
        $user = User::factory()->create()->assignRole('viewer');
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

    public function test_le_reporting_consolide_respecte_le_perimetre_du_viewer(): void
    {
        $user = User::factory()->create()->assignRole('viewer');
        $this->actingAs($user);

        $warehouseAllowed = $this->makeWarehouse();
        $this->makeWarehouse(); // hors périmètre

        $user->warehouses()->attach($warehouseAllowed);

        $widget = new WarehouseStockOverview();
        $results = $widget->table(Table::make($widget))->getQuery()->get();

        $this->assertCount(1, $results);
        $this->assertSame($warehouseAllowed->id, $results->first()->id);
    }

    /*
     * =================================================================
     * D4 — WarehouseResource (T10) hors périmètre du scoping viewer
     * =================================================================
     */

    public function test_un_viewer_sans_entrepot_attribue_voit_quand_meme_la_liste_complete_des_entrepots(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();

        $this->get(WarehouseResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($warehouseA->name)
            ->assertSee($warehouseB->name);
    }

    /*
     * =================================================================
     * D3 — scoping exclusif au rôle viewer (jamais aux comptes sans rôle)
     * =================================================================
     */

    public function test_un_utilisateur_sans_role_reste_illimite_malgre_labsence_dentrepot_attribue(): void
    {
        // D3 — le scoping en lecture T21 est exclusif au rôle viewer :
        // un compte authentifié sans AUCUN rôle (qui se comportait déjà
        // comme un lecteur illimité avant T21) doit le rester à
        // l'identique, jamais basculé en fail-closed par erreur.
        $this->actingAs(User::factory()->create()); // ni rôle, ni entrepôt attribué

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $line = WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'stock' => 5]);

        Livewire::test(ListWarehouseStocks::class)
            ->assertCanSeeTableRecords([$line]);
    }

    /*
     * =================================================================
     * Non-régression admin
     * =================================================================
     */

    public function test_un_admin_reste_illimite_apres_lintroduction_du_scoping_viewer(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $line = WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'stock' => 5]);
        $movement = StockMovement::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 5]);

        Livewire::test(ListWarehouseStocks::class)->assertCanSeeTableRecords([$line]);
        Livewire::test(ListStockMovements::class)->assertCanSeeTableRecords([$movement]);
        $this->get(StockMovementResource::getUrl('view', ['record' => $movement]))->assertSuccessful();
    }
}
