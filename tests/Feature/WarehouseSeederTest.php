<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T10 : WarehouseSeeder est un seeder de RÉCONCILIATION (lecture
 * seule), pas de démonstration. Ces tests vérifient en particulier la
 * contrainte la plus stricte de cette étape : ne JAMAIS modifier
 * products/product_variants.
 *
 * Vérifié précisément avant d'écrire ces tests (et corrigé après un
 * premier échec) : `products` n'a PAS de colonne `warehouse` en base,
 * contrairement à ce que ProductForm.php pourrait laisser croire —
 * seule `product_variants` en a une. Ces tests reflètent donc la
 * réalité du schéma, pas une supposition.
 */
class WarehouseSeederTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Produit T10',
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

    public function test_le_seeder_importe_les_valeurs_distinctes_de_warehouse_sur_product_variants(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product, ['warehouse' => 'Marseille']);
        $this->makeVariant($product, ['warehouse' => 'Marseille']); // doublon, ne doit pas dupliquer
        $this->makeVariant($product, ['warehouse' => 'Lyon']);
        $this->makeVariant($product, ['warehouse' => null]); // ignoré

        $this->seed(WarehouseSeeder::class);

        $this->assertTrue(Warehouse::where('name', 'Marseille')->exists());
        $this->assertTrue(Warehouse::where('name', 'Lyon')->exists());
        $this->assertSame(1, Warehouse::where('name', 'Marseille')->count());
    }

    public function test_le_seeder_cree_un_entrepot_par_defaut(): void
    {
        $this->seed(WarehouseSeeder::class);

        $default = Warehouse::where('is_default', true)->first();

        $this->assertNotNull($default);
        $this->assertSame('defaut', $default->code);
    }

    public function test_le_seeder_est_idempotent(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product, ['warehouse' => 'Marseille']);

        $this->seed(WarehouseSeeder::class);
        $countAfterFirstRun = Warehouse::count();

        $this->seed(WarehouseSeeder::class);

        $this->assertSame($countAfterFirstRun, Warehouse::count());
        $this->assertSame(1, Warehouse::where('is_default', true)->count());
    }

    /**
     * La vérification la plus importante de T10 : le seeder ne doit
     * JAMAIS écrire dans products/product_variants — uniquement les
     * lire. Compare l'état exact des deux tables avant/après.
     */
    public function test_le_seeder_ne_modifie_jamais_products_ni_product_variants(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 3, 'warehouse' => 'Lyon']);

        $productBefore = $product->fresh()->getAttributes();
        $variantBefore = $variant->fresh()->getAttributes();
        $productCountBefore = Product::count();
        $variantCountBefore = ProductVariant::count();

        $this->seed(WarehouseSeeder::class);

        $this->assertSame($productCountBefore, Product::count());
        $this->assertSame($variantCountBefore, ProductVariant::count());
        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($variantBefore, $variant->fresh()->getAttributes());
    }
}
