<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier 1 (plan de refactorisation MAGARROU ERP, étape 6/6) — preuve de
 * non-régression du socle générique posé aux étapes 1 à 5 : les 3
 * nouveaux modèles (AttributeDefinition, ProductAttributeValue,
 * ProductVariantAttributeValue) et les 2 relations ajoutées sur
 * Product/ProductVariant ne font qu'exposer un schéma déjà en place.
 * Ce test ne vérifie donc AUCUNE contrainte nouvelle : il prouve que
 * celles déjà écrites aux étapes 3/6, 4/6 et 5/6 se comportent comme
 * documenté dans les docblocks des migrations correspondantes, et que
 * Sport (colonnes dédiées existantes) n'est affecté par rien de tout
 * cela.
 *
 * RefreshDatabase (SQLite en mémoire, cf. phpunit.xml) : chaque test
 * tourne sur une base jetable, distincte de la base locale de dev.
 * Aucune donnée persistante n'est écrite hors de cette base éphémère.
 */
class Tier1AttributeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribute_definition_code_must_be_unique(): void
    {
        AttributeDefinition::create([
            'code' => 'color',
            'label' => 'Couleur',
            'level' => 'variant',
        ]);

        $this->expectException(QueryException::class);

        AttributeDefinition::create([
            'code' => 'color',
            'label' => 'Couleur (doublon)',
            'level' => 'variant',
        ]);
    }

    public function test_attribute_definition_level_only_accepts_product_or_variant(): void
    {
        $this->expectException(QueryException::class);

        AttributeDefinition::create([
            'code' => 'invalid_level',
            'label' => 'Niveau invalide',
            'level' => 'not_a_valid_level',
        ]);
    }

    public function test_product_attribute_value_rejects_duplicate_pair(): void
    {
        $product = Product::factory()->create();
        $definition = AttributeDefinition::create([
            'code' => 'season',
            'label' => 'Saison',
            'level' => 'product',
        ]);

        ProductAttributeValue::create([
            'product_id' => $product->id,
            'attribute_definition_id' => $definition->id,
            'value' => '2025-2026',
        ]);

        $this->expectException(QueryException::class);

        ProductAttributeValue::create([
            'product_id' => $product->id,
            'attribute_definition_id' => $definition->id,
            'value' => '2026-2027',
        ]);
    }

    public function test_product_variant_attribute_value_rejects_duplicate_pair(): void
    {
        $variant = ProductVariant::factory()->create();
        $definition = AttributeDefinition::create([
            'code' => 'cylindree',
            'label' => 'Cylindrée',
            'level' => 'variant',
        ]);

        ProductVariantAttributeValue::create([
            'product_variant_id' => $variant->id,
            'attribute_definition_id' => $definition->id,
            'value' => '125cc',
        ]);

        $this->expectException(QueryException::class);

        ProductVariantAttributeValue::create([
            'product_variant_id' => $variant->id,
            'attribute_definition_id' => $definition->id,
            'value' => '50cc',
        ]);
    }

    public function test_deleting_attribute_definition_cascades_to_both_value_tables(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create();
        $definition = AttributeDefinition::create([
            'code' => 'material',
            'label' => 'Matière',
            'level' => 'product',
        ]);

        $productValue = ProductAttributeValue::create([
            'product_id' => $product->id,
            'attribute_definition_id' => $definition->id,
            'value' => 'Cuir',
        ]);

        $variantValue = ProductVariantAttributeValue::create([
            'product_variant_id' => $variant->id,
            'attribute_definition_id' => $definition->id,
            'value' => 'Cuir',
        ]);

        $definition->delete();

        $this->assertDatabaseMissing('product_attribute_values', ['id' => $productValue->id]);
        $this->assertDatabaseMissing('product_variant_attribute_values', ['id' => $variantValue->id]);
    }

    public function test_deleting_product_without_history_cascades_its_attribute_values(): void
    {
        $product = Product::factory()->create();
        $definition = AttributeDefinition::create([
            'code' => 'origin',
            'label' => 'Origine',
            'level' => 'product',
        ]);

        $value = ProductAttributeValue::create([
            'product_id' => $product->id,
            'attribute_definition_id' => $definition->id,
            'value' => 'France',
        ]);

        $product->delete();

        $this->assertDatabaseMissing('product_attribute_values', ['id' => $value->id]);
    }

    public function test_deleting_product_variant_without_history_cascades_its_attribute_values(): void
    {
        $variant = ProductVariant::factory()->create();
        $definition = AttributeDefinition::create([
            'code' => 'finish',
            'label' => 'Finition',
            'level' => 'variant',
        ]);

        $value = ProductVariantAttributeValue::create([
            'product_variant_id' => $variant->id,
            'attribute_definition_id' => $definition->id,
            'value' => 'Mat',
        ]);

        $variant->delete();

        $this->assertDatabaseMissing('product_variant_attribute_values', ['id' => $value->id]);
    }

    public function test_relations_expose_the_existing_foreign_keys(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $definition = AttributeDefinition::create([
            'code' => 'weight',
            'label' => 'Poids',
            'level' => 'product',
        ]);

        $productValue = ProductAttributeValue::create([
            'product_id' => $product->id,
            'attribute_definition_id' => $definition->id,
            'value' => '1.2kg',
        ]);

        $variantValue = ProductVariantAttributeValue::create([
            'product_variant_id' => $variant->id,
            'attribute_definition_id' => $definition->id,
            'value' => '1.3kg',
        ]);

        $this->assertTrue($product->attributeValues->contains($productValue));
        $this->assertTrue($variant->attributeValues->contains($variantValue));
        $this->assertTrue($productValue->product->is($product));
        $this->assertTrue($productValue->attributeDefinition->is($definition));
        $this->assertTrue($variantValue->productVariant->is($variant));
        $this->assertTrue($definition->productValues->contains($productValue));
        $this->assertTrue($definition->variantValues->contains($variantValue));
    }

    public function test_sport_dedicated_columns_are_untouched_by_the_new_tables(): void
    {
        $product = Product::factory()->create([
            'club_id' => null,
            'competition_id' => null,
            'equipe' => 'Equipe Test',
            'taille' => 'M',
            'season' => '2025-2026',
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'size' => 'M',
            'color' => 'Bleu',
            'version' => 'Player Version',
        ]);

        $this->assertSame('sport', $product->fresh()->activity);

        $definition = AttributeDefinition::create([
            'code' => 'sleeve_length',
            'label' => 'Longueur de manche',
            'level' => 'variant',
        ]);

        ProductVariantAttributeValue::create([
            'product_variant_id' => $variant->id,
            'attribute_definition_id' => $definition->id,
            'value' => 'Courte',
        ]);

        $product->refresh();
        $variant->refresh();

        $this->assertSame('Equipe Test', $product->equipe);
        $this->assertSame('M', $product->taille);
        $this->assertSame('2025-2026', $product->season);
        $this->assertSame('M', $variant->size);
        $this->assertSame('Bleu', $variant->color);
        $this->assertSame('Player Version', $variant->version);
    }
}
