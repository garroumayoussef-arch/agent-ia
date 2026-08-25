<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Database\Seeders\StockMovementWarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Étape T11b — StockMovementWarehouseSeeder est un seeder de
 * RÉCONCILIATION (lecture/écriture ciblée, idempotent), même famille
 * que WarehouseSeeder/WarehouseStockSeeder (T10/T11a). Rattache les
 * stock_movements historiques (warehouse_id NULL) à l'entrepôt par
 * défaut, sans jamais toucher aux lignes déjà attribuées.
 */
class StockMovementWarehouseSeederTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Produit T11b',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    /**
     * Insère un mouvement "historique" directement en base, en
     * contournant StockMovement::creating() (qui résoudrait déjà
     * l'entrepôt) — simule un mouvement créé avant l'ajout de la
     * colonne warehouse_id.
     */
    private function insertLegacyMovement(int $productId, ?int $warehouseId = null): int
    {
        return DB::table('stock_movements')->insertGetId([
            'product_id' => $productId,
            'type' => 'purchase',
            'quantity' => 5,
            'stock_before' => 0,
            'stock_after' => 5,
            'warehouse_id' => $warehouseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_un_mouvement_historique_sans_entrepot_est_rattache_a_lentrepot_par_defaut(): void
    {
        $default = Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);
        $product = $this->makeProduct();
        $movementId = $this->insertLegacyMovement($product->id);

        $this->seed(StockMovementWarehouseSeeder::class);

        $this->assertSame($default->id, StockMovement::find($movementId)->warehouse_id);
    }

    public function test_un_mouvement_deja_associe_a_un_entrepot_nest_jamais_modifie(): void
    {
        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);
        $autre = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b']);
        $product = $this->makeProduct();
        $movementId = $this->insertLegacyMovement($product->id, $autre->id);

        $this->seed(StockMovementWarehouseSeeder::class);

        $this->assertSame($autre->id, StockMovement::find($movementId)->warehouse_id);
    }

    public function test_le_seeder_est_idempotent(): void
    {
        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);
        $product = $this->makeProduct();
        $this->insertLegacyMovement($product->id);
        $this->insertLegacyMovement($product->id);

        $this->seed(StockMovementWarehouseSeeder::class);
        $premierPassage = StockMovement::pluck('warehouse_id', 'id')->toArray();

        $this->seed(StockMovementWarehouseSeeder::class);
        $secondPassage = StockMovement::pluck('warehouse_id', 'id')->toArray();

        $this->assertSame($premierPassage, $secondPassage);
    }

    public function test_le_seeder_ne_fait_rien_sans_entrepot_par_defaut(): void
    {
        // Aucun Warehouse créé.
        $product = $this->makeProduct();
        $movementId = $this->insertLegacyMovement($product->id);

        $this->seed(StockMovementWarehouseSeeder::class);

        $this->assertNull(StockMovement::find($movementId)->warehouse_id);
    }
}
