<?php

namespace Tests\Feature;

use App\Filament\Resources\WarehouseStocks\Pages\ListWarehouseStocks;
use App\Filament\Resources\WarehouseStocks\WarehouseStockResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T14 — écran de consultation warehouse_stocks, strictement en
 * lecture seule. Les tests d'autorisation (rôles, chauffeur) vivent
 * dans RoleBasedAuthorizationTest.php, comme pour toutes les autres
 * Resources — ce fichier couvre le comportement fonctionnel propre à
 * cette Resource.
 */
class WarehouseStockResourceTest extends TestCase
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
            'nom' => 'Produit T14',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    public function test_la_liste_affiche_les_lignes_warehouse_stock_existantes(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t14']);
        $product = $this->makeProduct(['nom' => 'Maillot T14 Domicile']);
        $line = WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock' => 12,
        ]);

        Livewire::test(ListWarehouseStocks::class)
            ->assertCanSeeTableRecords([$line])
            ->assertSee('Entrepôt A')
            ->assertSee('Maillot T14 Domicile')
            ->assertSee('12');
    }

    public function test_la_liste_affiche_le_libelle_de_la_variante(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t14-variant']);
        $product = $this->makeProduct();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-T14-VARIANT',
            'size' => 'L',
            'stock' => 4,
            'status' => 'active',
        ]);
        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock' => 4,
        ]);

        Livewire::test(ListWarehouseStocks::class)
            ->assertSee('SKU-T14-VARIANT');
    }

    public function test_le_filtre_par_entrepot_fonctionne(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $warehouseA = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a-t14-filter']);
        $warehouseB = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b-t14-filter']);
        $product = $this->makeProduct();

        $lineA = WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'stock' => 5]);
        $lineB = WarehouseStock::create(['warehouse_id' => $warehouseB->id, 'product_id' => $product->id, 'stock' => 8]);

        Livewire::test(ListWarehouseStocks::class)
            ->filterTable('warehouse_id', $warehouseA->id)
            ->assertCanSeeTableRecords([$lineA])
            ->assertCanNotSeeTableRecords([$lineB]);
    }

    /*
     * =================================================================
     * Garantie structurelle : aucune page de mutation
     * =================================================================
     */

    public function test_la_resource_ne_declare_aucune_page_de_creation_edition_ou_vue(): void
    {
        $pages = WarehouseStockResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
        $this->assertArrayNotHasKey('view', $pages);
    }
}
