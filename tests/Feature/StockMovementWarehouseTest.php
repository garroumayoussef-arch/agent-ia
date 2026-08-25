<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Étape T11b — StockMovement est désormais le point d'écriture qui
 * tient warehouse_stocks à jour (dimension entrepôt), en plus du
 * stock global Product/ProductVariant déjà couvert par
 * StockMovementTest.php. Contrairement à ce dernier, ce fichier ne
 * crée PAS d'entrepôt par défaut dans un setUp() commun : certains
 * tests (absence d'entrepôt par défaut) ont justement besoin de son
 * absence.
 */
class StockMovementWarehouseTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeVariant(Product $product, array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'SKU-'.uniqid(),
            'size' => 'M',
            'stock' => 0,
            'status' => 'active',
        ], $attributes));
    }

    private function makeDefaultWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'name' => 'Entrepôt par défaut',
            'code' => 'defaut',
            'is_default' => true,
        ], $attributes));
    }

    /*
     * =================================================================
     * Résolution de l'entrepôt à la création
     * =================================================================
     */

    public function test_un_mouvement_sans_entrepot_explicite_est_rattache_a_lentrepot_par_defaut(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 0]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->assertSame($default->id, $movement->warehouse_id);
    }

    public function test_un_mouvement_avec_entrepot_explicite_conserve_cet_entrepot(): void
    {
        $this->makeDefaultWarehouse();
        $autre = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b']);
        $product = $this->makeProduct(['stock' => 0]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $autre->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->assertSame($autre->id, $movement->warehouse_id);
    }

    public function test_la_creation_est_refusee_si_aucun_entrepot_par_defaut_nexiste(): void
    {
        // Aucun Warehouse créé du tout dans ce test.
        $product = $this->makeProduct(['stock' => 0]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'purchase',
                'quantity' => 5,
            ]);
        } finally {
            $this->assertSame(0, StockMovement::count());
            $this->assertSame(0, $product->fresh()->stock);
        }
    }

    public function test_on_ne_peut_pas_changer_lentrepot_dun_mouvement_existant(): void
    {
        $default = $this->makeDefaultWarehouse();
        $autre = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b']);
        $product = $this->makeProduct(['stock' => 0]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->expectException(\Exception::class);

        try {
            $movement->update(['warehouse_id' => $autre->id]);
        } finally {
            $movement->refresh();
            $this->assertSame($default->id, $movement->warehouse_id);
        }
    }

    /*
     * =================================================================
     * warehouse_stocks tenu à jour par les mouvements
     * =================================================================
     */

    public function test_le_warehouse_stock_est_incremente_apres_un_achat(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 0]);

        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 12,
        ]);

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->first();

        $this->assertNotNull($line);
        $this->assertSame(12, $line->stock);
    }

    public function test_le_warehouse_stock_est_decremente_apres_une_vente(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);

        // Une ligne warehouse_stocks doit d'abord exister (simulée
        // comme si T11a l'avait rétro-remplie).
        WarehouseStock::create([
            'warehouse_id' => $default->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock' => 10,
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'sale',
            'quantity' => 4,
        ]);

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertSame(6, $line->stock);
    }

    public function test_le_warehouse_stock_sauto_cree_si_absent_avant_le_mouvement(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 0]);
        $variant = $this->makeVariant($product, ['stock' => 0]);

        // Aucune ligne warehouse_stocks pré-existante pour cette
        // variante : le mouvement doit l'auto-créer (firstOrCreate),
        // pas échouer silencieusement.
        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 3,
        ]);

        $this->assertSame(
            3,
            WarehouseStock::where('warehouse_id', $default->id)
                ->where('product_variant_id', $variant->id)
                ->value('stock')
        );
    }

    public function test_la_somme_des_warehouse_stock_reste_coherente_avec_le_stock_global_apres_plusieurs_mouvements(): void
    {
        $this->makeDefaultWarehouse();
        $product = $this->makeProduct();
        $variantA = $this->makeVariant($product, ['sku' => 'SKU-A', 'stock' => 0]);
        $variantB = $this->makeVariant($product, ['sku' => 'SKU-B', 'stock' => 0]);

        StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variantA->id, 'type' => 'purchase', 'quantity' => 10]);
        StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variantB->id, 'type' => 'purchase', 'quantity' => 6]);
        StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variantA->id, 'type' => 'sale', 'quantity' => 3]);

        $product->refresh();

        $sommeWarehouseStock = WarehouseStock::where('product_id', $product->id)->sum('stock');

        $this->assertSame(13, $product->stock); // (10 - 3) + 6
        $this->assertSame($product->stock, $sommeWarehouseStock);
    }

    public function test_supprimer_un_mouvement_retablit_le_warehouse_stock(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat1 = StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'type' => 'purchase', 'quantity' => 10]);
        StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'type' => 'purchase', 'quantity' => 5]);

        $achat1->delete();

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertSame(5, $line->stock);
        $this->assertSame(5, $variant->fresh()->stock);
    }

    public function test_modifier_la_quantite_dun_mouvement_recalcule_le_warehouse_stock(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat = StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'type' => 'purchase', 'quantity' => 10]);

        $achat->update(['quantity' => 20]);

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertSame(20, $line->stock);
        $this->assertSame(20, $variant->fresh()->stock);
    }

    /*
     * =================================================================
     * Mouvement historique (warehouse_id NULL) rejoué par resyncLedger
     * =================================================================
     */

    public function test_editer_un_mouvement_historique_sans_entrepot_le_rattache_a_lentrepot_par_defaut(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 10]);

        // Simule un mouvement créé AVANT T11b (warehouse_id NULL),
        // en insérant directement en base pour contourner
        // StockMovement::creating() (qui résoudrait déjà l'entrepôt).
        $movementId = DB::table('stock_movements')->insertGetId([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 10,
            'stock_before' => 0,
            'stock_after' => 10,
            'warehouse_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $movement = StockMovement::find($movementId);
        $this->assertNull($movement->warehouse_id);

        // Une édition déclenche resyncLedger(), qui doit résoudre le
        // repli sur l'entrepôt par défaut pour ce mouvement historique
        // sans planter (warehouse_id = 0 serait rejeté par la FK).
        $movement->update(['quantity' => 15]);

        $product->refresh();
        $this->assertSame(15, $product->stock);

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->first();

        $this->assertNotNull($line);
        $this->assertSame(15, $line->stock);
    }

    /*
     * =================================================================
     * Garde de suppression d'un entrepôt référencé par des mouvements
     * =================================================================
     */

    public function test_un_entrepot_reference_par_des_mouvements_ne_peut_pas_etre_supprime(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 0]);

        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->expectException(\Exception::class);

        try {
            $default->delete();
        } finally {
            $this->assertDatabaseHas('warehouses', ['id' => $default->id]);
        }
    }
}
