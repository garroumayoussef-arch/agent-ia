<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Nombre de produits de démonstration à générer.
     */
    private const PRODUCTS_COUNT = 30;

    /**
     * Tailles disponibles pour générer les variantes de démonstration.
     */
    private const SIZES = ['XS', 'S', 'M', 'L', 'XL', '2XL'];

    public function run(): void
    {
        // Idempotent : ne duplique pas les produits de démo si le
        // seeder est rejoué (contrairement à BrandSeeder/CategorySeeder/
        // etc., un "produit" n'a pas de clé naturelle fiable à utiliser
        // avec firstOrCreate()).
        if (Product::count() >= self::PRODUCTS_COUNT) {
            return;
        }

        // S'appuie sur les données déjà injectées par BrandSeeder /
        // CategorySeeder / ClubSeeder / CompetitionSeeder / SupplierSeeder
        // (cf. DatabaseSeeder, appelés avant celui-ci). Si une table est
        // vide, les produits sont simplement créés sans cette relation :
        // toutes ces colonnes sont nullable côté modèle.
        $brandIds = Brand::pluck('id');
        $categoryIds = Category::pluck('id');
        $clubIds = Club::pluck('id');
        $competitionIds = Competition::pluck('id');
        $supplierIds = Supplier::pluck('id');

        for ($i = 0; $i < self::PRODUCTS_COUNT; $i++) {
            $product = Product::factory()->create([
                'brand_id' => $brandIds->isNotEmpty() ? $brandIds->random() : null,
                'category_id' => $categoryIds->isNotEmpty() ? $categoryIds->random() : null,
                'club_id' => $clubIds->isNotEmpty() ? $clubIds->random() : null,
                'competition_id' => $competitionIds->isNotEmpty() ? $competitionIds->random() : null,
                'supplier_id' => $supplierIds->isNotEmpty() ? $supplierIds->random() : null,
            ]);

            // Environ 2 produits sur 3 reçoivent plusieurs variantes
            // (tailles distinctes) ; les autres restent des produits
            // "simples" sans variante (stock géré directement sur
            // Product) — afin de couvrir les deux cas gérés par
            // StockMovement (CAS 1 : variante / CAS 2 : produit seul).
            if ($i % 3 !== 0) {
                $sizes = collect(self::SIZES)
                    ->shuffle()
                    ->take(fake()->numberBetween(2, 4));

                foreach ($sizes as $size) {
                    ProductVariant::factory()->for($product)->create([
                        'size' => $size,
                    ]);
                }
                // Product.stock est automatiquement resynchronisé par
                // ProductVariant::syncProductStock() à chaque variante créée.
            } else {
                $product->update(['stock' => fake()->numberBetween(0, 40)]);
            }
        }
    }
}
