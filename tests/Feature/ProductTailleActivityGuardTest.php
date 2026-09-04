<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier 2, étape 2.6.1 — non-régression et vérification d'indépendance à
 * Sport du hook `Product::saving()` qui dérive `taille` depuis une
 * variante. La dérivation ne s'applique désormais que si
 * `attribute_definitions` déclare "taille" comme attribut de niveau
 * produit APPLICABLE à l'activité du produit (transverse `activity=null`,
 * ou scopé à cette activité précise) — jamais via un nom d'activité codé
 * en dur dans `Product.php`.
 *
 * Note : une variante ne peut exister qu'APRÈS son produit parent (FK
 * `product_id`). La dérivation depuis une variante ne peut donc se
 * déclencher que lors d'une re-sauvegarde du produit avec `taille`
 * explicitement vidée (ex. admin qui efface le champ), jamais à la
 * création — chaque scénario ci-dessous reproduit fidèlement cet ordre.
 */
class ProductTailleActivityGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_sport_product_still_derives_taille_from_variant_when_seeded(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $product = Product::factory()->create(['activity' => 'sport', 'taille' => 'M']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'L']);

        $product->taille = '';
        $product->save();

        $this->assertSame('L', $product->fresh()->taille);
    }

    public function test_sport_product_without_variant_still_falls_back_to_na_when_seeded(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $product = Product::factory()->create(['activity' => 'sport', 'taille' => 'M']);

        $product->taille = '';
        $product->save();

        $this->assertSame('N/A', $product->fresh()->taille);
    }

    public function test_sport_product_does_not_derive_taille_when_no_definition_seeded(): void
    {
        // Aucun seed : la table attribute_definitions est vide.
        $product = Product::factory()->create(['activity' => 'sport', 'taille' => 'M']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'L']);

        $product->taille = '';
        $product->save();

        // Sans définition applicable, MEME Sport n'a plus de dérivation :
        // preuve que le hook ne traite pas Sport comme un cas par défaut.
        $this->assertSame('N/A', $product->fresh()->taille);
    }

    public function test_future_activity_with_its_own_definition_gets_derivation_too(): void
    {
        // Simule une future activité (ex. Bébé) sans dépendre d'aucune
        // activité codée en dur dans Product.php : seule la présence de
        // cette ligne en base pilote le comportement.
        AttributeDefinition::create([
            'code' => 'taille',
            'label' => 'Taille',
            'activity' => 'bebe',
            'level' => 'product',
            'input_type' => 'text',
            'is_required' => false,
        ]);

        $product = Product::factory()->create(['activity' => 'bebe', 'taille' => 'M']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'S']);

        $product->taille = '';
        $product->save();

        $this->assertSame('S', $product->fresh()->taille);
    }

    public function test_activity_without_applicable_definition_never_derives_from_variant(): void
    {
        $this->seed(AttributeDefinitionSeeder::class); // ne définit "taille" que pour 'sport'

        $product = Product::factory()->create(['activity' => 'vtc', 'taille' => 'M']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'L']);

        $product->taille = '';
        $product->save();

        $this->assertSame('N/A', $product->fresh()->taille);
    }
}
