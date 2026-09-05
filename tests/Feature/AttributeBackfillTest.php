<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tier 2 (préparation), étape 2.3 — non-régression de la commande
 * `attributes:backfill`. Simule un catalogue "legacy" (créé avant
 * l'activation du dual-write) en supprimant les lignes miroir que le
 * dual-write de l'étape 2.2 a créées automatiquement à la sauvegarde,
 * puis vérifie que le backfill les reconstruit fidèlement — sans
 * jamais modifier `products`/`product_variants`.
 */
class AttributeBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AttributeDefinitionSeeder::class);
    }

    public function test_backfill_populates_missing_mirror_rows_for_existing_records(): void
    {
        $product = Product::factory()->create([
            'season' => '2025-2026',
            'taille' => 'M',
            'equipe' => 'Equipe Test',
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'size' => 'L',
            'color' => 'Bleu',
            'version' => 'Player Version',
        ]);

        // Simule un catalogue existant AVANT le dual-write : les
        // miroirs créés automatiquement à la création ci-dessus sont
        // effacés, comme s'ils n'avaient jamais existé.
        ProductAttributeValue::query()->delete();
        ProductVariantAttributeValue::query()->delete();

        $this->assertSame(0, ProductAttributeValue::count());
        $this->assertSame(0, ProductVariantAttributeValue::count());

        Artisan::call('attributes:backfill');

        $this->assertSame(
            '2025-2026',
            ProductAttributeValue::where('product_id', $product->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'season'))
                ->value('value')
        );
        $this->assertSame(
            'M',
            ProductAttributeValue::where('product_id', $product->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'taille'))
                ->value('value')
        );
        $this->assertSame(
            'Equipe Test',
            ProductAttributeValue::where('product_id', $product->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'equipe'))
                ->value('value')
        );

        $this->assertSame(
            'L',
            ProductVariantAttributeValue::where('product_variant_id', $variant->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'size'))
                ->value('value')
        );
        $this->assertSame(
            'Bleu',
            ProductVariantAttributeValue::where('product_variant_id', $variant->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'color'))
                ->value('value')
        );
        $this->assertSame(
            'Player Version',
            ProductVariantAttributeValue::where('product_variant_id', $variant->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'version'))
                ->value('value')
        );
    }

    public function test_backfill_is_idempotent_no_duplicates(): void
    {
        $product = Product::factory()->create(['season' => '2025-2026']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'M']);

        Artisan::call('attributes:backfill');
        Artisan::call('attributes:backfill');
        Artisan::call('attributes:backfill');

        $this->assertSame(
            1,
            ProductAttributeValue::where('product_id', $product->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'season'))
                ->count()
        );
        $this->assertSame(
            1,
            ProductVariantAttributeValue::where('product_variant_id', $variant->id)
                ->whereHas('attributeDefinition', fn ($q) => $q->where('code', 'size'))
                ->count()
        );
    }

    public function test_backfill_does_not_modify_source_columns_or_timestamps(): void
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

        $productUpdatedAtBefore = $product->fresh()->updated_at;
        $variantUpdatedAtBefore = $variant->fresh()->updated_at;

        // Avance l'horloge pour rendre un éventuel UPDATE non désiré
        // détectable via updated_at (preuve qu'aucune écriture SQL
        // n'a eu lieu sur products/product_variants).
        $this->travel(1)->hour();

        Artisan::call('attributes:backfill');

        $productAfter = $product->fresh();
        $variantAfter = $variant->fresh();

        $this->assertSame('2025-2026', $productAfter->season);
        $this->assertSame('M', $productAfter->taille);
        $this->assertSame('Equipe Test', $productAfter->equipe);
        $this->assertSame('Fan', $productAfter->version);
        $this->assertSame('L', $variantAfter->size);
        $this->assertSame('Bleu', $variantAfter->color);
        $this->assertSame('Player Version', $variantAfter->version);

        $this->assertTrue($productUpdatedAtBefore->equalTo($productAfter->updated_at));
        $this->assertTrue($variantUpdatedAtBefore->equalTo($variantAfter->updated_at));
    }

    public function test_backfill_reports_exact_counts(): void
    {
        Product::factory()->count(3)->create();
        ProductVariant::factory()->count(2)->create();

        // 3 produits + 2 variantes, chacune créant automatiquement son
        // propre produit parent (ProductVariantFactory) => 3 + 2 = 5
        // produits au total, 2 variantes au total.
        $this->assertSame(5, Product::count());
        $this->assertSame(2, ProductVariant::count());

        Artisan::call('attributes:backfill');
        $output = Artisan::output();

        $this->assertStringContainsString('Produits traités : 5', $output);
        $this->assertStringContainsString('Variantes traitées : 2', $output);
    }
}
