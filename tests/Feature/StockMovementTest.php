<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-' . uniqid(),
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
            'sku' => 'SKU-' . uniqid(),
            'size' => 'M',
            'stock' => 0,
            'status' => 'active',
        ], $attributes));
    }

    public function test_un_produit_peut_etre_cree_avec_les_nouvelles_relations_sans_champs_legacy(): void
    {
        $brand = Brand::create(['name' => 'Nike', 'slug' => 'nike']);
        $category = Category::create(['name' => 'Football', 'slug' => 'football']);
        $club = Club::create(['name' => 'Paris Saint-Germain', 'slug' => 'paris-saint-germain']);
        $competition = Competition::create(['name' => 'Ligue 1', 'slug' => 'ligue-1']);
        $supplier = Supplier::create(['name' => 'AliExpress']);

        $product = Product::create([
            'reference' => 'REF-NEW',
            'nom' => 'Maillot PSG',
            'type' => 'Player Version',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'club_id' => $club->id,
            'competition_id' => $competition->id,
            'supplier_id' => $supplier->id,
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);

        $this->assertNotNull($product->id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('Football', $product->categorie);
        $this->assertSame('Nike', $product->marque);
        $this->assertSame('AliExpress', $product->fournisseur);
        $this->assertSame('N/A', $product->taille);
    }

    public function test_un_achat_sur_variante_met_a_jour_le_stock_de_la_variante_et_du_produit(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $variant->refresh();
        $product->refresh();

        $this->assertSame(15, $variant->stock);
        $this->assertSame(15, $product->stock);
    }

    public function test_le_stock_produit_reste_synchronise_quand_une_variante_est_editee_directement(): void
    {
        $product = $this->makeProduct();
        $variantA = $this->makeVariant($product, ['sku' => 'SKU-A', 'stock' => 3]);
        $variantB = $this->makeVariant($product, ['sku' => 'SKU-B', 'stock' => 4]);

        // Édition directe (hors StockMovement), comme via le Repeater du formulaire Produit.
        $variantA->update(['stock' => 10]);

        $product->refresh();

        $this->assertSame(14, $product->stock); // 10 + 4
    }

    public function test_une_vente_en_stock_insuffisant_est_rejetee_sans_ecriture_partielle(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 2]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => 'sale',
                'quantity' => 5,
            ]);
        } finally {
            $variant->refresh();
            $product->refresh();

            // Aucune écriture partielle : stock inchangé (2, synchronisé dès la création de la variante).
            $this->assertSame(2, $variant->stock);
            $this->assertSame(2, $product->stock);

            // Aucune ligne "fantôme" créée dans stock_movements.
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_un_ajustement_peut_legalement_ramener_le_stock_a_zero(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 8]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'adjustment',
            'quantity' => 0,
        ]);

        $variant->refresh();
        $product->refresh();

        $this->assertSame(0, $variant->stock);
        $this->assertSame(0, $product->stock);
    }

    public function test_un_mouvement_de_type_transfert_est_rejete_sans_ecriture(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => 'transfer',
                'quantity' => 1,
            ]);
        } finally {
            $variant->refresh();

            $this->assertSame(5, $variant->stock);
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_les_champs_json_du_produit_sont_bien_castes_en_tableau(): void
    {
        $product = $this->makeProduct([
            'photos' => ['photo1.jpg', 'photo2.jpg'],
            'marketplaces' => ['amazon', 'ebay'],
            'featured' => true,
            'status' => true,
        ]);

        $product->refresh();

        $this->assertIsArray($product->photos);
        $this->assertSame(['photo1.jpg', 'photo2.jpg'], $product->photos);
        $this->assertIsArray($product->marketplaces);
        $this->assertSame(['amazon', 'ebay'], $product->marketplaces);
        $this->assertIsBool($product->featured);
        $this->assertTrue($product->featured);
        $this->assertIsBool($product->status);
        $this->assertTrue($product->status);
    }
}
