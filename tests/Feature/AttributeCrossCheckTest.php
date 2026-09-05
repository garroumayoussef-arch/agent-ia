<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tier 2 (préparation), étape 2.4 — vérification croisée (lecture
 * parallèle, sans bascule). Compare, pour l'ensemble d'un catalogue
 * Sport simulé, la valeur lue depuis le système d'attributs génériques
 * (Product::attributeMirrorValue()/ProductVariant::attributeMirrorValue())
 * à la colonne dédiée correspondante — égalité stricte exigée partout.
 * Aucune bascule : les colonnes dédiées restent la seule source lue
 * par le reste de l'application, cet accessor n'est utilisé qu'ici.
 */
class AttributeCrossCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AttributeDefinitionSeeder::class);
    }

    public function test_product_mirror_matches_dedicated_columns_across_the_whole_catalog(): void
    {
        $products = Product::factory()->count(10)->create();

        foreach ($products as $product) {
            $this->assertSame(
                $product->season,
                $product->attributeMirrorValue('season'),
                "Divergence season pour le produit #{$product->id}"
            );
            $this->assertSame(
                $product->taille,
                $product->attributeMirrorValue('taille'),
                "Divergence taille pour le produit #{$product->id}"
            );
            $this->assertSame(
                $product->equipe,
                $product->attributeMirrorValue('equipe'),
                "Divergence equipe pour le produit #{$product->id}"
            );
        }
    }

    public function test_variant_mirror_matches_dedicated_columns_across_the_whole_catalog(): void
    {
        $variants = ProductVariant::factory()->count(10)->create();

        foreach ($variants as $variant) {
            $this->assertSame(
                $variant->size,
                $variant->attributeMirrorValue('size'),
                "Divergence size pour la variante #{$variant->id}"
            );
            $this->assertSame(
                $variant->color,
                $variant->attributeMirrorValue('color'),
                "Divergence color pour la variante #{$variant->id}"
            );
            $this->assertSame(
                $variant->version,
                $variant->attributeMirrorValue('version'),
                "Divergence version pour la variante #{$variant->id}"
            );
        }
    }

    public function test_cross_check_also_covers_records_backfilled_from_a_legacy_state(): void
    {
        // Catalogue créé, puis miroirs effacés pour simuler un état
        // "avant dual-write" (comme AttributeBackfillTest, étape 2.3),
        // reconstruit par le backfill — la vérification croisée doit
        // rester exacte y compris sur des données rétroactivement
        // peuplées, pas seulement sur des écritures fraîches.
        $products = Product::factory()->count(5)->create();
        $variants = ProductVariant::factory()->count(5)->create();

        \App\Models\ProductAttributeValue::query()->delete();
        \App\Models\ProductVariantAttributeValue::query()->delete();

        Artisan::call('attributes:backfill');

        foreach ($products as $product) {
            $this->assertSame($product->season, $product->fresh()->attributeMirrorValue('season'));
            $this->assertSame($product->taille, $product->fresh()->attributeMirrorValue('taille'));
            $this->assertSame($product->equipe, $product->fresh()->attributeMirrorValue('equipe'));
        }

        foreach ($variants as $variant) {
            $this->assertSame($variant->size, $variant->fresh()->attributeMirrorValue('size'));
            $this->assertSame($variant->color, $variant->fresh()->attributeMirrorValue('color'));
            $this->assertSame($variant->version, $variant->fresh()->attributeMirrorValue('version'));
        }
    }

    public function test_products_version_has_no_mirror_to_compare_against(): void
    {
        // products.version reste hors du système d'attributs (conflit
        // résolu précédemment) : l'accessor ne doit jamais renvoyer de
        // valeur pour ce code au niveau produit, il n'y a rien à
        // basculer un jour, aucune divergence n'est même mesurable ici.
        $product = Product::factory()->create(['version' => 'Fan']);

        $this->assertNull($product->attributeMirrorValue('version'));
    }

    public function test_dedicated_columns_are_not_read_from_the_mirror_by_any_other_code(): void
    {
        // L'accessor est un ajout PARALLÈLE, jamais une bascule : lire
        // directement la colonne dédiée continue de fonctionner
        // indépendamment de l'existence ou non d'une ligne miroir.
        $product = Product::factory()->create(['season' => '2025-2026']);

        \App\Models\ProductAttributeValue::query()->delete();

        $this->assertNull($product->attributeMirrorValue('season'));
        $this->assertSame('2025-2026', $product->fresh()->season);
    }
}
