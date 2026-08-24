<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T11a : WarehouseStock est encore une table de données pure —
 * aucune logique de synchronisation avec StockMovement (T11b). Ces
 * tests portent uniquement sur ce que T11a introduit réellement :
 * l'entité elle-même, ses relations, sa contrainte d'unicité, et la
 * garde de suppression sur Warehouse (différée en T10, fermée ici).
 */
class WarehouseStockTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Produit T11a',
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
            'stock' => 0,
            'status' => 'active',
        ], $attributes));
    }

    public function test_une_ligne_warehouse_stock_peut_etre_creee_pour_un_produit_sans_variante(): void
    {
        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a']);
        $product = $this->makeProduct(['stock' => 42]);

        $line = WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock' => 42,
        ]);

        $this->assertNull($line->product_variant_id);
        $this->assertSame($warehouse->id, $line->warehouse->id);
        $this->assertSame($product->id, $line->product->id);
    }

    public function test_une_ligne_warehouse_stock_peut_etre_creee_pour_une_variante(): void
    {
        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a']);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 7]);

        $line = WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock' => 7,
        ]);

        $this->assertSame($variant->id, $line->productVariant->id);
    }

    /**
     * Vérifie explicitement la contrainte d'unicité pour le cas où
     * elle fonctionne réellement au niveau SQL (product_variant_id non
     * NULL) — cf. commentaire de la migration pour le cas NULL, non
     * garanti par la contrainte seule.
     */
    public function test_la_contrainte_dunicite_refuse_un_doublon_warehouse_produit_variante(): void
    {
        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a']);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);

        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock' => 5,
        ]);

        $this->expectException(QueryException::class);
        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock' => 9,
        ]);
    }

    /*
     * =================================================================
     * Garde de suppression sur Warehouse (différée en T10, fermée ici)
     * =================================================================
     */

    public function test_un_entrepot_sans_stock_peut_etre_supprime(): void
    {
        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a']);

        $warehouse->delete();

        $this->assertDatabaseMissing('warehouses', ['id' => $warehouse->id]);
    }

    public function test_un_entrepot_reference_par_du_stock_ne_peut_pas_etre_supprime(): void
    {
        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a']);
        $product = $this->makeProduct();
        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock' => 10,
        ]);

        $this->expectException(\Exception::class);
        $warehouse->delete();
    }

    public function test_lentrepot_nest_pas_supprime_apres_une_tentative_refusee(): void
    {
        $warehouse = Warehouse::create(['name' => 'Entrepôt A', 'code' => 'a']);
        $product = $this->makeProduct();
        WarehouseStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock' => 10,
        ]);

        try {
            $warehouse->delete();
        } catch (\Throwable $e) {
            // Attendu — on vérifie seulement l'absence d'effet de bord.
        }

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
    }
}
