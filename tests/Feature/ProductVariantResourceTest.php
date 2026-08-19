<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductVariants\Pages\CreateProductVariant;
use App\Filament\Resources\ProductVariants\Pages\EditProductVariant;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductVariantResourceTest extends TestCase
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
     * Accessibilité des pages
     * =================================================================
     */

    public function test_les_pages_de_la_ressource_sont_accessibles(): void
    {
        $this->actingAs(User::factory()->create());

        $product = $this->makeProduct();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-EDIT-TEST',
            'size' => 'M',
            'stock' => 5,
            'status' => 'active',
        ]);

        $this->get(ProductVariantResource::getUrl('index'))->assertSuccessful();
        $this->get(ProductVariantResource::getUrl('create'))->assertSuccessful();
        $this->get(ProductVariantResource::getUrl('edit', ['record' => $variant]))->assertSuccessful();
    }

    /*
     * =================================================================
     * Création via le formulaire Livewire
     * =================================================================
     */

    public function test_creer_une_variante_depuis_le_formulaire_synchronise_le_stock_du_produit(): void
    {
        $this->actingAs(User::factory()->create());

        $product = $this->makeProduct(['stock' => 0]);

        Livewire::test(CreateProductVariant::class)
            ->fillForm([
                'product_id' => $product->id,
                'sku' => 'SKU-LIVEWIRE-CREATE',
                'size' => 'L',
                'stock' => 12,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'SKU-LIVEWIRE-CREATE',
            'stock' => 12,
        ]);

        // Le hook ProductVariant::syncProductStock() doit se déclencher
        // exactement comme via le Repeater imbriqué dans Product.
        $product->refresh();
        $this->assertSame(12, $product->stock);
    }

    public function test_le_sku_doit_etre_unique_a_la_creation(): void
    {
        $this->actingAs(User::factory()->create());

        $productA = $this->makeProduct();
        ProductVariant::create([
            'product_id' => $productA->id,
            'sku' => 'SKU-DUPLICATE',
            'size' => 'M',
            'stock' => 1,
            'status' => 'active',
        ]);

        $productB = $this->makeProduct();

        Livewire::test(CreateProductVariant::class)
            ->fillForm([
                'product_id' => $productB->id,
                'sku' => 'SKU-DUPLICATE',
                'stock' => 3,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasFormErrors(['sku']);

        $this->assertSame(1, ProductVariant::where('sku', 'SKU-DUPLICATE')->count());
    }

    /*
     * =================================================================
     * Édition via le formulaire Livewire
     * =================================================================
     */

    public function test_modifier_le_stock_dune_variante_depuis_le_formulaire_resynchronise_le_produit(): void
    {
        $this->actingAs(User::factory()->create());

        $product = $this->makeProduct();
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-LIVEWIRE-EDIT',
            'size' => 'M',
            'stock' => 5,
            'status' => 'active',
        ]);

        $product->refresh();
        $this->assertSame(5, $product->stock);

        Livewire::test(EditProductVariant::class, ['record' => $variant->getKey()])
            ->fillForm(['stock' => 20])
            ->call('save')
            ->assertHasNoFormErrors();

        $variant->refresh();
        $product->refresh();

        $this->assertSame(20, $variant->stock);
        $this->assertSame(20, $product->stock);
    }
}
