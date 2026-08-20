<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\BrandSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ClubSeeder;
use Database\Seeders\CompetitionSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSeederTest extends TestCase
{
    use RefreshDatabase;

    /*
     * =================================================================
     * ProductFactory
     * =================================================================
     */

    public function test_product_factory_genere_un_produit_valide_sans_relation(): void
    {
        $product = Product::factory()->create();

        $this->assertNotNull($product->id);
        $this->assertNotEmpty($product->reference);
        $this->assertNotEmpty($product->nom);
        $this->assertNotEmpty($product->type);
        $this->assertNull($product->brand_id);
        $this->assertNull($product->category_id);
        // categorie/marque/fournisseur retombent sur 'N/A' via
        // Product::syncMirroredRelationField() en l'absence de relation.
        $this->assertSame('N/A', $product->categorie);
        $this->assertSame('N/A', $product->marque);
        $this->assertSame('N/A', $product->fournisseur);
    }

    public function test_product_factory_genere_des_references_et_sku_uniques(): void
    {
        $products = Product::factory()->count(20)->create();

        $this->assertSame(20, $products->pluck('reference')->unique()->count());
        $this->assertSame(20, $products->pluck('sku')->unique()->count());
    }

    public function test_product_factory_accepte_des_relations_explicites(): void
    {
        $brand = Brand::create(['name' => 'Nike', 'slug' => 'nike']);
        $category = Category::create(['name' => 'Football', 'slug' => 'football']);

        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ]);

        $this->assertSame('Nike', $product->marque);
        $this->assertSame('Football', $product->categorie);
    }

    /*
     * =================================================================
     * ProductVariantFactory
     * =================================================================
     */

    public function test_product_variant_factory_cree_automatiquement_son_produit_parent(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->assertNotNull($variant->product_id);
        $this->assertInstanceOf(Product::class, $variant->product);
    }

    public function test_product_variant_factory_rattachee_a_un_produit_precis_synchronise_le_stock(): void
    {
        $product = Product::factory()->create();

        ProductVariant::factory()->for($product)->create(['stock' => 5]);
        ProductVariant::factory()->for($product)->create(['stock' => 7]);

        $product->refresh();

        $this->assertSame(12, $product->stock);
    }

    /*
     * =================================================================
     * ProductSeeder
     * =================================================================
     */

    public function test_product_seeder_genere_des_produits_avec_et_sans_variantes(): void
    {
        $this->seed(BrandSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(ClubSeeder::class);
        $this->seed(CompetitionSeeder::class);
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);

        $products = Product::with('variants')->get();

        $this->assertGreaterThanOrEqual(30, $products->count());

        $withVariants = $products->filter(fn (Product $p) => $p->variants->isNotEmpty());
        $withoutVariants = $products->filter(fn (Product $p) => $p->variants->isEmpty());

        // Les deux cas gérés par StockMovement doivent être représentés.
        $this->assertGreaterThan(0, $withVariants->count());
        $this->assertGreaterThan(0, $withoutVariants->count());

        // Le stock de chaque produit avec variantes doit être la somme
        // exacte des stocks de ses variantes (invariant garanti par
        // ProductVariant::syncProductStock()).
        foreach ($withVariants as $product) {
            $this->assertSame(
                (int) $product->variants->sum('stock'),
                $product->stock,
                "Stock désynchronisé pour le produit #{$product->id}"
            );
        }
    }

    public function test_product_seeder_est_idempotent(): void
    {
        $this->seed(BrandSeeder::class);
        $this->seed(CategorySeeder::class);
        $this->seed(ClubSeeder::class);
        $this->seed(CompetitionSeeder::class);
        $this->seed(SupplierSeeder::class);
        $this->seed(ProductSeeder::class);

        $countAfterFirstRun = Product::count();

        // Rejouer le seeder ne doit pas dupliquer les produits de démo.
        $this->seed(ProductSeeder::class);

        $this->assertSame($countAfterFirstRun, Product::count());
    }
}
