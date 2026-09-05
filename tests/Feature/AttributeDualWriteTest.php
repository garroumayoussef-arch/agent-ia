<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier 2 (préparation), étape 2.2 — non-régression du dual-write
 * (miroir) ajouté dans Product::booted()/ProductVariant::booted().
 * Vérifie que sauvegarder un Product/ProductVariant Sport alimente les
 * tables miroir SANS jamais modifier les colonnes dédiées, que
 * l'opération est idempotente (aucun doublon en cas de resave), et que
 * `products.version` reste totalement hors du système d'attributs.
 */
class AttributeDualWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AttributeDefinitionSeeder::class);
    }

    public function test_saving_a_product_mirrors_season_taille_equipe(): void
    {
        $product = Product::factory()->create([
            'season' => '2025-2026',
            'taille' => 'M',
            'equipe' => 'Equipe Test',
        ]);

        $this->assertSame('2025-2026', $this->mirroredProductValue($product, 'season'));
        $this->assertSame('M', $this->mirroredProductValue($product, 'taille'));
        $this->assertSame('Equipe Test', $this->mirroredProductValue($product, 'equipe'));
    }

    public function test_saving_a_variant_mirrors_size_color_version(): void
    {
        $variant = ProductVariant::factory()->create([
            'size' => 'L',
            'color' => 'Bleu',
            'version' => 'Player Version',
        ]);

        $this->assertSame('L', $this->mirroredVariantValue($variant, 'size'));
        $this->assertSame('Bleu', $this->mirroredVariantValue($variant, 'color'));
        $this->assertSame('Player Version', $this->mirroredVariantValue($variant, 'version'));
    }

    public function test_products_version_is_never_mirrored(): void
    {
        $product = Product::factory()->create(['version' => 'Fan']);

        $this->assertNull($this->mirroredProductValue($product, 'version'));
        $this->assertSame(
            0,
            ProductAttributeValue::where('product_id', $product->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'version'))
                ->count()
        );
    }

    public function test_dedicated_columns_remain_unchanged_after_mirroring(): void
    {
        $product = Product::factory()->create([
            'season' => '2025-2026',
            'taille' => 'M',
            'equipe' => 'Equipe Test',
            'version' => 'Fan',
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'size' => 'L',
            'color' => 'Bleu',
            'version' => 'Player Version',
        ]);

        $product->refresh();
        $variant->refresh();

        $this->assertSame('2025-2026', $product->season);
        $this->assertSame('M', $product->taille);
        $this->assertSame('Equipe Test', $product->equipe);
        $this->assertSame('Fan', $product->version);
        $this->assertSame('L', $variant->size);
        $this->assertSame('Bleu', $variant->color);
        $this->assertSame('Player Version', $variant->version);
    }

    public function test_resaving_the_same_product_does_not_duplicate_mirror_rows(): void
    {
        $product = Product::factory()->create(['season' => '2025-2026']);

        $product->season = '2026-2027';
        $product->save();
        $product->save();

        $this->assertSame(
            1,
            ProductAttributeValue::where('product_id', $product->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'season'))
                ->count()
        );
        $this->assertSame('2026-2027', $this->mirroredProductValue($product, 'season'));
    }

    public function test_resaving_the_same_variant_does_not_duplicate_mirror_rows(): void
    {
        $variant = ProductVariant::factory()->create(['size' => 'M']);

        $variant->size = 'L';
        $variant->save();
        $variant->save();

        $this->assertSame(
            1,
            ProductVariantAttributeValue::where('product_variant_id', $variant->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'size'))
                ->count()
        );
        $this->assertSame('L', $this->mirroredVariantValue($variant, 'size'));
    }

    public function test_null_values_are_not_mirrored(): void
    {
        $product = Product::factory()->create(['season' => null]);

        $this->assertNull($this->mirroredProductValue($product, 'season'));
    }

    public function test_saving_still_works_when_attribute_definitions_are_missing(): void
    {
        // Simule un environnement où le seed de l'étape 2.1 n'a pas
        // encore tourné : le dual-write ne doit jamais bloquer la
        // sauvegarde normale d'un produit/variante Sport.
        \App\Models\AttributeDefinition::query()->delete();

        $product = Product::factory()->create(['season' => '2025-2026']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'M']);

        $this->assertSame('2025-2026', $product->fresh()->season);
        $this->assertSame('M', $variant->fresh()->size);
        $this->assertSame(0, ProductAttributeValue::count());
        $this->assertSame(0, ProductVariantAttributeValue::count());
    }

    private function mirroredProductValue(Product $product, string $code): ?string
    {
        return ProductAttributeValue::where('product_id', $product->id)
            ->whereHas('attributeDefinition', fn ($q) => $q->where('code', $code))
            ->value('value');
    }

    private function mirroredVariantValue(ProductVariant $variant, string $code): ?string
    {
        return ProductVariantAttributeValue::where('product_variant_id', $variant->id)
            ->whereHas('attributeDefinition', fn ($q) => $q->where('code', $code))
            ->value('value');
    }
}
