<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\WarehouseSeeder;
use Database\Seeders\WarehouseStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T11a : WarehouseStockSeeder est un seeder de RÉCONCILIATION
 * (lecture seule), pas de démonstration — même famille que
 * WarehouseSeeder (T10). Ces tests vérifient en particulier la
 * contrainte la plus stricte de cette étape : ne JAMAIS modifier
 * products/product_variants, et la cohérence de la représentation du
 * stock rétro-rempli (invariant central demandé pour T11a).
 */
class WarehouseStockSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // WarehouseStockSeeder dépend de l'entrepôt is_default créé
        // par WarehouseSeeder (T10).
        $this->seed(WarehouseSeeder::class);
    }

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

    public function test_un_produit_sans_variante_recoit_une_ligne_dans_lentrepot_par_defaut(): void
    {
        $product = $this->makeProduct(['stock' => 25]);

        $this->seed(WarehouseStockSeeder::class);

        $default = Warehouse::where('is_default', true)->first();
        $line = WarehouseStock::where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->first();

        $this->assertNotNull($line);
        $this->assertSame($default->id, $line->warehouse_id);
        $this->assertSame(25, $line->stock);
    }

    public function test_un_produit_avec_variantes_ne_recoit_aucune_ligne_directe(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product, ['stock' => 3]);

        $this->seed(WarehouseStockSeeder::class);

        $this->assertFalse(
            WarehouseStock::where('product_id', $product->id)
                ->whereNull('product_variant_id')
                ->exists()
        );
    }

    public function test_chaque_variante_recoit_sa_propre_ligne(): void
    {
        $product = $this->makeProduct();
        $variantA = $this->makeVariant($product, ['stock' => 3]);
        $variantB = $this->makeVariant($product, ['stock' => 8]);

        $this->seed(WarehouseStockSeeder::class);

        $default = Warehouse::where('is_default', true)->first();

        $lineA = WarehouseStock::where('product_variant_id', $variantA->id)->first();
        $lineB = WarehouseStock::where('product_variant_id', $variantB->id)->first();

        $this->assertSame(3, $lineA->stock);
        $this->assertSame(8, $lineB->stock);
        $this->assertSame($default->id, $lineA->warehouse_id);
        $this->assertSame($default->id, $lineB->warehouse_id);
    }

    /**
     * Invariant central demandé pour T11a : la somme des lignes
     * warehouse_stocks d'un produit (directe, ou via ses variantes)
     * doit reconstituer exactement son stock global actuel — même
     * principe que ProductVariant::syncProductStock() déjà en place.
     */
    public function test_la_somme_des_lignes_warehouse_stock_reconcilie_avec_le_stock_global(): void
    {
        $productSansVariante = $this->makeProduct(['stock' => 12]);

        $productAvecVariantes = $this->makeProduct(['stock' => 15]); // Product.stock synchronisé par ProductVariant
        $this->makeVariant($productAvecVariantes, ['stock' => 6]);
        $this->makeVariant($productAvecVariantes, ['stock' => 9]);

        $this->seed(WarehouseStockSeeder::class);

        $sommeSansVariante = WarehouseStock::where('product_id', $productSansVariante->id)->sum('stock');
        $sommeAvecVariantes = WarehouseStock::where('product_id', $productAvecVariantes->id)->sum('stock');

        $this->assertSame(12, $sommeSansVariante);
        $this->assertSame(15, $sommeAvecVariantes);
        $this->assertSame($productAvecVariantes->fresh()->stock, $sommeAvecVariantes);
    }

    public function test_le_seeder_est_idempotent(): void
    {
        $product = $this->makeProduct(['stock' => 5]);

        $this->seed(WarehouseStockSeeder::class);
        $countAfterFirstRun = WarehouseStock::count();

        $this->seed(WarehouseStockSeeder::class);

        $this->assertSame($countAfterFirstRun, WarehouseStock::count());
    }

    /**
     * La vérification la plus importante de T11a, comme pour T10 :
     * le seeder ne doit JAMAIS écrire dans products/product_variants —
     * uniquement les lire.
     */
    public function test_le_seeder_ne_modifie_jamais_products_ni_product_variants(): void
    {
        $product = $this->makeProduct(['stock' => 20]);
        $variant = $this->makeVariant($product, ['stock' => 4]);

        $productBefore = $product->fresh()->getAttributes();
        $variantBefore = $variant->fresh()->getAttributes();
        $productCountBefore = Product::count();
        $variantCountBefore = ProductVariant::count();

        $this->seed(WarehouseStockSeeder::class);

        $this->assertSame($productCountBefore, Product::count());
        $this->assertSame($variantCountBefore, ProductVariant::count());
        $this->assertSame($productBefore, $product->fresh()->getAttributes());
        $this->assertSame($variantBefore, $variant->fresh()->getAttributes());
    }
}
