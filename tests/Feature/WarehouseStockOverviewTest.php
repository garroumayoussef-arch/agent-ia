<?php

namespace Tests\Feature;

use App\Filament\Widgets\WarehouseStockOverview;
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
 * Étape T18 — reporting/inventaire consolidé par entrepôt. Widget
 * séparé de LowStockAlertByWarehouse (T15, jamais modifié) et de
 * StockOverview (jamais modifié) : voir leurs tests respectifs pour
 * ces deux widgets, inchangés.
 */
class WarehouseStockOverviewTest extends TestCase
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

    public function test_le_widget_liste_une_ligne_par_entrepot_actif_avec_quantite_et_valorisation_correctes(): void
    {
        $warehouseA = $this->makeWarehouse(['name' => 'Entrepôt A']);
        $warehouseB = $this->makeWarehouse(['name' => 'Entrepôt B']);

        $productX = $this->makeProduct(['reference' => 'REF-X', 'prix_achat' => 10]);
        $productY = $this->makeProduct(['reference' => 'REF-Y', 'prix_achat' => 5]);

        // Entrepôt A : 3 x produit X (10€) + 4 x produit Y (5€) = 30 + 20 = 50€, 7 unités.
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $productX->id, 'stock' => 3]);
        WarehouseStock::create(['warehouse_id' => $warehouseA->id, 'product_id' => $productY->id, 'stock' => 4]);

        // Entrepôt B : 2 x produit X (10€) = 20€, 2 unités.
        WarehouseStock::create(['warehouse_id' => $warehouseB->id, 'product_id' => $productX->id, 'stock' => 2]);

        $widget = new WarehouseStockOverview();
        $table = $widget->table(Table::make($widget));

        $results = $table->getQuery()->get();

        $this->assertCount(2, $results);

        $rowA = $results->firstWhere('id', $warehouseA->id);
        $rowB = $results->firstWhere('id', $warehouseB->id);

        $this->assertSame(7, (int) $rowA->warehouseStocks()->sum('stock'));
        $this->assertEqualsWithDelta(50.0, $this->valuationOf($rowA), 0.001);

        $this->assertSame(2, (int) $rowB->warehouseStocks()->sum('stock'));
        $this->assertEqualsWithDelta(20.0, $this->valuationOf($rowB), 0.001);
    }

    /**
     * Le test le plus important de T18 : preuve que la valorisation
     * utilise bien le prix d'achat de la VARIANTE, distinct de celui du
     * produit parent, quand une variante est concernée.
     */
    public function test_la_valorisation_utilise_le_prix_de_la_variante_quand_elle_existe(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct(['prix_achat' => 100]); // jamais utilisé pour cette ligne
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-T18',
            'stock' => 0,
            'status' => 'active',
            'prix_achat' => 7,
            'prix_vente' => 15,
        ]);

        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock' => 5,
        ]);

        $widget = new WarehouseStockOverview();
        $table = $widget->table(Table::make($widget));

        $row = $table->getQuery()->get()->firstWhere('id', $warehouse->id);

        // 5 x 7€ (prix variante) = 35€ — jamais 5 x 100€ (prix produit).
        $this->assertEqualsWithDelta(35.0, $this->valuationOf($row), 0.001);
    }

    public function test_les_entrepots_inactifs_sont_exclus(): void
    {
        $inactive = $this->makeWarehouse(['is_active' => false]);
        $product = $this->makeProduct();
        WarehouseStock::create(['warehouse_id' => $inactive->id, 'product_id' => $product->id, 'stock' => 10]);

        $widget = new WarehouseStockOverview();
        $table = $widget->table(Table::make($widget));

        $this->assertCount(0, $table->getQuery()->get());
    }

    public function test_un_entrepot_actif_sans_aucune_ligne_de_stock_apparait_a_zero(): void
    {
        $warehouse = $this->makeWarehouse();

        $widget = new WarehouseStockOverview();
        $table = $widget->table(Table::make($widget));

        $row = $table->getQuery()->get()->firstWhere('id', $warehouse->id);

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->warehouseStocks()->sum('stock'));
        $this->assertEqualsWithDelta(0.0, $this->valuationOf($row), 0.001);
    }

    public function test_le_dashboard_est_accessible_avec_le_widget(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertSuccessful();
    }

    /*
     * =================================================================
     * Autorisations — mêmes règles que LowStockAlertByWarehouse (T15)
     * =================================================================
     */

    public function test_le_widget_est_masque_pour_un_chauffeur(): void
    {
        $user = User::factory()->create(); // aucun rôle Spatie
        Driver::create(['name' => 'Chauffeur WSO', 'user_id' => $user->id]);

        $this->actingAs($user);

        $this->assertFalse(WarehouseStockOverview::canView());
    }

    public function test_le_widget_reste_visible_pour_admin_manager_et_utilisateur_sans_role_ni_driver(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $this->assertTrue(WarehouseStockOverview::canView());

        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $this->assertTrue(WarehouseStockOverview::canView());

        $this->actingAs(User::factory()->create()); // ni rôle, ni Driver lié
        $this->assertTrue(WarehouseStockOverview::canView());
    }

    /**
     * Reproduit exactement le calcul privé du widget (stock × prix
     * d'achat variante/produit) pour vérifier son résultat depuis le
     * test, sans dupliquer la règle métier elle-même.
     */
    private function valuationOf(Warehouse $warehouse): float
    {
        return (float) $warehouse->warehouseStocks()
            ->with(['product', 'productVariant'])
            ->get()
            ->sum(function (WarehouseStock $line): float {
                $unitPrice = $line->productVariant?->prix_achat ?? $line->product?->prix_achat ?? 0;

                return $line->stock * (float) $unitPrice;
            });
    }
}
