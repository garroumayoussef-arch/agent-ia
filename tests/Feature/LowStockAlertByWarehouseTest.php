<?php

namespace Tests\Feature;

use App\Filament\Widgets\LowStockAlertByWarehouse;
use App\Models\Driver;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\RoleSeeder;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T15 — widget séparé de LowStockAlert (jamais modifié), basé
 * sur warehouse_stocks. Voir LowStockAlertTest.php pour les tests du
 * widget global, inchangés.
 */
class LowStockAlertByWarehouseTest extends TestCase
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

    private function makeWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'name' => 'Entrepôt '.uniqid(),
            'code' => 'w-'.uniqid(),
        ], $attributes));
    }

    /*
     * =================================================================
     * Cas nominal
     * =================================================================
     */

    public function test_le_widget_liste_uniquement_les_lignes_en_stock_bas_triees_par_stock_croissant(): void
    {
        $warehouse = $this->makeWarehouse();
        $productRupture = $this->makeProduct(['reference' => 'REF-RUPTURE']);
        $productBas = $this->makeProduct(['reference' => 'REF-BAS']);
        $productOk = $this->makeProduct(['reference' => 'REF-OK']);

        $ruptureLine = WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $productRupture->id, 'stock' => 0]);
        $basLine = WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $productBas->id, 'stock' => 3]);
        WarehouseStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $productOk->id, 'stock' => 100]);

        $widget = new LowStockAlertByWarehouse();
        $table = $widget->table(Table::make($widget));

        $results = $table->getQuery()->get();

        $this->assertCount(2, $results);
        $this->assertSame($ruptureLine->id, $results->first()->id);
        $this->assertSame($basLine->id, $results->last()->id);
    }

    /**
     * Le test le plus important de T15 : preuve de l'angle mort évité
     * par le widget global — un produit bas dans UN entrepôt doit
     * apparaître ici même si son stock global reste confortable.
     */
    public function test_un_produit_bas_dans_un_entrepot_apparait_meme_si_le_stock_global_est_suffisant(): void
    {
        $warehouseA = $this->makeWarehouse();
        $warehouseB = $this->makeWarehouse();
        $product = $this->makeProduct();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-T15',
            'stock' => 13, // stock global largement suffisant
            'status' => 'active',
        ]);

        $lowLine = WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 2]);
        WarehouseStock::create(['warehouse_id' => $warehouseB->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'stock' => 11]);

        $widget = new LowStockAlertByWarehouse();
        $table = $widget->table(Table::make($widget));

        $results = $table->getQuery()->get();

        $this->assertCount(1, $results);
        $this->assertSame($lowLine->id, $results->first()->id);
    }

    public function test_les_entrepots_inactifs_sont_exclus(): void
    {
        $inactive = $this->makeWarehouse(['is_active' => false]);
        $product = $this->makeProduct();
        WarehouseStock::create(['warehouse_id' => $inactive->id, 'product_id' => $product->id, 'stock' => 0]);

        $widget = new LowStockAlertByWarehouse();
        $table = $widget->table(Table::make($widget));

        $this->assertCount(0, $table->getQuery()->get());
    }

    public function test_le_dashboard_est_accessible_avec_le_widget(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertSuccessful();
    }

    /*
     * =================================================================
     * Autorisations — mêmes règles que LowStockAlert
     * =================================================================
     */

    public function test_le_widget_est_masque_pour_un_chauffeur(): void
    {
        $user = User::factory()->create(); // aucun rôle Spatie
        Driver::create(['name' => 'Chauffeur LSAW', 'user_id' => $user->id]);

        $this->actingAs($user);

        $this->assertFalse(LowStockAlertByWarehouse::canView());
    }

    public function test_le_widget_reste_visible_pour_admin_manager_et_utilisateur_sans_role_ni_driver(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->assertTrue(LowStockAlertByWarehouse::canView());

        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $this->assertTrue(LowStockAlertByWarehouse::canView());

        $this->actingAs(User::factory()->create()); // ni rôle, ni Driver lié
        $this->assertTrue(LowStockAlertByWarehouse::canView());
    }
}
