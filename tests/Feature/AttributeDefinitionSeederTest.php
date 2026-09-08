<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tier 2 (préparation), étape 2.1 — puis étape 2.6.5 (extension du
 * référentiel aux autres activités du Core ERP) — non-régression du
 * peuplement des 13 définitions d'attribut par AttributeDefinitionSeeder :
 * season, taille, equipe, size, color, version (Sport, historique),
 * tranche_age, taille_bebe (Bébé), modele_compatible, cylindree (Moto),
 * matiere, region_origine, dimension (Artisanat du Maroc). Aucune
 * définition VTC (n'utilise ni Product ni ProductVariant). Ne vérifie que
 * ce seeder : aucune donnée `products`/`product_variants` n'est créée ni
 * lue ici.
 */
class AttributeDefinitionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_CODES = [
        'season', 'taille', 'equipe', 'size', 'color', 'version',
        'tranche_age', 'taille_bebe',
        'modele_compatible', 'cylindree',
        'matiere', 'region_origine', 'dimension',
    ];

    public function test_seeding_once_creates_exactly_thirteen_definitions(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertSame(13, AttributeDefinition::count());
        $this->assertEqualsCanonicalizing(
            self::ALL_CODES,
            AttributeDefinition::pluck('code')->all()
        );
    }

    public function test_seeding_twice_is_idempotent_no_duplicates(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertSame(13, AttributeDefinition::count());
        $this->assertSame(
            13,
            AttributeDefinition::whereIn('code', self::ALL_CODES)
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

    /**
     * Étape 2.6.5 — `color` est la seule définition existante MODIFIÉE
     * (jamais dupliquée, contrainte UNIQUE sur `code`) : son `activity`
     * passe de 'sport' à null, la rendant transversale (partagée par
     * toutes les activités), conformément à la décision métier validée.
     */
    public function test_color_is_transversal_with_null_activity(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertSame(
            1,
            AttributeDefinition::where('code', 'color')->count(),
            'color ne doit jamais être dupliqué (une seule ligne, contrainte UNIQUE sur code).'
        );

        $color = AttributeDefinition::where('code', 'color')->first();

        $this->assertNull($color->activity);
        $this->assertSame('variant', $color->level);
    }

    public function test_taille_is_required_others_are_not(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertTrue(AttributeDefinition::where('code', 'taille')->value('is_required'));

        $others = array_values(array_diff(self::ALL_CODES, ['taille']));

        foreach ($others as $code) {
            $this->assertFalse(
                (bool) AttributeDefinition::where('code', $code)->value('is_required'),
                "Le code '{$code}' ne devrait pas être requis."
            );
        }
    }

    public function test_select_definitions_carry_options_text_definitions_do_not(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $selectCodes = ['size', 'version', 'tranche_age', 'taille_bebe', 'cylindree', 'region_origine'];
        $textCodes = ['season', 'taille', 'equipe', 'color', 'modele_compatible', 'matiere', 'dimension'];

        foreach ($selectCodes as $code) {
            $definition = AttributeDefinition::where('code', $code)->first();
            $this->assertNotNull($definition, "Définition '{$code}' introuvable.");
            $this->assertSame('select', $definition->input_type);
            $this->assertIsArray($definition->options);
            $this->assertNotEmpty($definition->options);
        }

        foreach ($textCodes as $code) {
            $definition = AttributeDefinition::where('code', $code)->first();
            $this->assertNotNull($definition, "Définition '{$code}' introuvable.");
            $this->assertSame('text', $definition->input_type);
            $this->assertNull($definition->options);
        }
    }

    /**
     * Étape 2.6.5 — Bébé au même niveau que les autres activités,
     * jamais un cas particulier codé en dur.
     */
    public function test_bebe_definitions_are_correctly_scoped(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $trancheAge = AttributeDefinition::where('code', 'tranche_age')->first();
        $this->assertSame('bebe', $trancheAge->activity);
        $this->assertSame('product', $trancheAge->level);
        $this->assertCount(4, $trancheAge->options);

        $tailleBebe = AttributeDefinition::where('code', 'taille_bebe')->first();
        $this->assertSame('bebe', $tailleBebe->activity);
        $this->assertSame('variant', $tailleBebe->level);
        $this->assertCount(12, $tailleBebe->options);
    }

    public function test_moto_definitions_are_correctly_scoped(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $modele = AttributeDefinition::where('code', 'modele_compatible')->first();
        $this->assertSame('moto', $modele->activity);
        $this->assertSame('product', $modele->level);

        $cylindree = AttributeDefinition::where('code', 'cylindree')->first();
        $this->assertSame('moto', $cylindree->activity);
        $this->assertSame('variant', $cylindree->level);
        $this->assertCount(6, $cylindree->options);
    }

    public function test_artisanat_definitions_are_correctly_scoped(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $matiere = AttributeDefinition::where('code', 'matiere')->first();
        $this->assertSame('artisanat', $matiere->activity);
        $this->assertSame('product', $matiere->level);

        $region = AttributeDefinition::where('code', 'region_origine')->first();
        $this->assertSame('artisanat', $region->activity);
        $this->assertSame('product', $region->level);
        $this->assertCount(10, $region->options);

        $dimension = AttributeDefinition::where('code', 'dimension')->first();
        $this->assertSame('artisanat', $dimension->activity);
        $this->assertSame('variant', $dimension->level);
    }

    /**
     * VTC n'utilise ni Product ni ProductVariant (cf. VtcRide, sans
     * référence à Product/ProductVariant/StockMovement) : aucune
     * définition ne doit exister pour cette activité.
     */
    public function test_vtc_has_no_definitions(): void
    {
        $this->seed(AttributeDefinitionSeeder::class);

        $this->assertSame(0, AttributeDefinition::where('activity', 'vtc')->count());
    }
}
