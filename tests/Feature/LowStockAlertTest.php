<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Widgets\LowStockAlert;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LowStockAlertTest extends TestCase
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

    /*
     * =================================================================
     * Badge de navigation — ProductResource
     * =================================================================
     */

    public function test_le_badge_produits_est_absent_quand_tout_le_stock_est_confortable(): void
    {
        $this->makeProduct(['stock' => Product::LOW_STOCK_THRESHOLD + 1]);

        $this->assertNull(ProductResource::getNavigationBadge());
    }

    public function test_le_badge_produits_compte_les_produits_en_stock_bas_et_est_orange(): void
    {
        $this->makeProduct(['stock' => Product::LOW_STOCK_THRESHOLD]); // seuil inclus
        $this->makeProduct(['stock' => Product::LOW_STOCK_THRESHOLD + 1]); // au-dessus, exclu

        $this->assertSame('1', ProductResource::getNavigationBadge());
        $this->assertSame('warning', ProductResource::getNavigationBadgeColor());
    }

    public function test_le_badge_produits_devient_rouge_des_quune_rupture_existe(): void
    {
        $this->makeProduct(['stock' => Product::LOW_STOCK_THRESHOLD]); // bas mais pas rupture
        $this->makeProduct(['stock' => 0]); // rupture

        $this->assertSame('2', ProductResource::getNavigationBadge());
        $this->assertSame('danger', ProductResource::getNavigationBadgeColor());
    }

    /*
     * =================================================================
     * Badge de navigation — ProductVariantResource
     * =================================================================
     */

    public function test_le_badge_variantes_compte_les_variantes_en_stock_bas(): void
    {
        $product = $this->makeProduct();

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-LOW',
            'stock' => 2,
            'status' => 'active',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-OK',
            'stock' => 50,
            'status' => 'active',
        ]);

        $this->assertSame('1', ProductVariantResource::getNavigationBadge());
        $this->assertSame('warning', ProductVariantResource::getNavigationBadgeColor());
    }

    /*
     * =================================================================
     * Widget LowStockAlert
     * =================================================================
     */

    public function test_le_widget_liste_uniquement_les_produits_en_stock_bas_tries_par_stock_croissant(): void
    {
        $rupture = $this->makeProduct(['reference' => 'REF-RUPTURE', 'stock' => 0]);
        $bas = $this->makeProduct(['reference' => 'REF-BAS', 'stock' => 3]);
        $this->makeProduct(['reference' => 'REF-OK', 'stock' => 100]);

        $widget = new LowStockAlert();
        $table = $widget->table(Table::make($widget));

        $results = $table->getQuery()->get();

        $this->assertCount(2, $results);
        $this->assertSame($rupture->id, $results->first()->id);
        $this->assertSame($bas->id, $results->last()->id);
    }

    public function test_le_dashboard_est_accessible_avec_le_widget_dalerte(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertSuccessful();
    }
}
