<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier 2 (préparation), étape 2.1 — non-régression du peuplement des 6
 * définitions d'attribut (season, taille, equipe, size, color, version)
 * par AttributeDefinitionSeeder. Ne vérifie que ce seeder : aucune
 * donnée `products`/`product_variants` n'est créée ni lue ici.
 */
class AttributeDefinitionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_once_creates_exactly_six_definitions(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertSame(6, AttributeDefinition::count());
        $this->assertEqualsCanonicalizing(
            ['season', 'taille', 'equipe', 'size', 'color', 'version'],
            AttributeDefinition::pluck('code')->all()
        );
    }

    public function test_seeding_twice_is_idempotent_no_duplicates(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertSame(6, AttributeDefinition::count());
        $this->assertSame(
            6,
            AttributeDefinition::whereIn('code', ['season', 'taille', 'equipe', 'size', 'color', 'version'])
                ->distinct('code')
                ->count('code')
        );
    }

    public function test_version_is_exclusively_variant_level(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $version = AttributeDefinition::where('code', 'version')->first();

        $this->assertNotNull($version);
        $this->assertSame('variant', $version->level);

        // Aucune ligne "product_version" ou "version" en level=product ne doit exister :
        // products.version est volontairement exclue de la migration (colonne inutilisée).
        $this->assertSame(
            0,
            AttributeDefinition::where('level', 'product')
                ->where('code', 'like', '%version%')
                ->count()
        );
    }

    public function test_taille_is_required_others_are_not(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertTrue(AttributeDefinition::where('code', 'taille')->value('is_required'));

        foreach (['season', 'equipe', 'size', 'color', 'version'] as $code) {
            $this->assertFalse(
                (bool) AttributeDefinition::where('code', $code)->value('is_required'),
                "Le code '{$code}' ne devrait pas être requis."
            );
        }
    }

    public function test_select_definitions_carry_options_text_definitions_do_not(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        foreach (['size', 'version'] as $code) {
            $definition = AttributeDefinition::where('code', $code)->first();
            $this->assertSame('select', $definition->input_type);
            $this->assertIsArray($definition->options);
            $this->assertNotEmpty($definition->options);
        }

        foreach (['season', 'taille', 'equipe', 'color'] as $code) {
            $definition = AttributeDefinition::where('code', $code)->first();
            $this->assertSame('text', $definition->input_type);
            $this->assertNull($definition->options);
        }
    }
}
