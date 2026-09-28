<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * 2.6.7 — constats historiques, pas règles cibles : ces scénarios rendent
     * les divergences visibles avant tout arbitrage sur les formulaires.
     * Les tests antérieurs et leurs assertions restent inchangés.
     */
    public function test_constat_effacer_season_conserve_le_miroir_preexistant(): void
    {
        $product = Product::factory()->create(['activity' => 'sport', 'season' => '2025-2026']);
        $this->assertSame('2025-2026', $product->attributeMirrorValue('season'));

        $product->update(['season' => null]);
        $product->refresh();
        $this->assertNull($product->season);
        $this->assertSame('2025-2026', $product->attributeMirrorValue('season'));
        $this->assertSame(1, $product->attributeValues()
            ->whereHas('attributeDefinition', fn ($query) => $query->where('code', 'season'))->count());

        $product->update(['season' => '2026-2027']);
        $this->assertSame('2026-2027', $product->fresh()->attributeMirrorValue('season'));
    }

    public static function variantMirrorCodes(): array
    {
        return ['size' => ['size', 'M', 'L'], 'color' => ['color', 'Bleu', 'Rouge'],
            'version' => ['version', 'Player Version', 'Fan Version']];
    }

    #[DataProvider('variantMirrorCodes')]
    public function test_constat_null_initial_et_effacement_ont_des_effets_differents_sur_le_miroir(string $code, string $initial, string $next): void
    {
        $product = Product::factory()->create(['activity' => 'sport']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, $code => null]);
        $this->assertNull($variant->fresh()->attributeMirrorValue($code));
        $variant->update([$code => $initial]);
        $this->assertSame($initial, $variant->fresh()->attributeMirrorValue($code));

        $variant->update([$code => null]);
        $this->assertNull($variant->fresh()->getAttribute($code));
        $this->assertSame($initial, $variant->fresh()->attributeMirrorValue($code));
        $variant->update([$code => $next]);
        $this->assertSame($next, $variant->fresh()->attributeMirrorValue($code));
        $this->assertSame(1, $variant->attributeValues()
            ->whereHas('attributeDefinition', fn ($query) => $query->where('code', $code))->count());
    }

    public function test_effacer_taille_et_equipe_declenche_les_replis_historiques_avant_copie(): void
    {
        $product = Product::factory()->create([
            'activity' => 'sport', 'taille' => 'L', 'equipe' => 'Equipe saisie', 'club_id' => null,
        ]);
        ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'M']);
        $product->update(['taille' => null, 'equipe' => null]);
        $product->refresh();

        $this->assertSame('M', $product->taille);
        $this->assertSame('M', $product->attributeMirrorValue('taille'));
        $this->assertSame('N/A', $product->equipe);
        $this->assertSame('N/A', $product->attributeMirrorValue('equipe'));
    }

    public function test_constat_changement_activite_conserve_les_valeurs_devenues_inapplicables(): void
    {
        $product = Product::factory()->create([
            'activity' => 'sport', 'season' => 'Ancienne saison', 'taille' => 'M', 'equipe' => 'Ancienne équipe',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'size' => 'M', 'color' => 'Bleu', 'version' => 'Player Version',
        ]);
        $product->update(['activity' => 'moto', 'season' => 'Nouvelle saison', 'taille' => 'L', 'equipe' => 'Nouvelle équipe']);
        $product->refresh();
        $this->assertSame('moto', $product->activity);
        foreach (['season' => ['Nouvelle saison', 'Ancienne saison'], 'taille' => ['L', 'M'],
            'equipe' => ['Nouvelle équipe', 'Ancienne équipe']] as $code => [$source, $mirror]) {
            $this->assertSame($source, $product->getAttribute($code));
            $this->assertSame($mirror, $product->attributeMirrorValue($code));
        }

        // Relecture volontaire : ce cas isole l'inapplicabilité du cache de relation.
        $variant->refresh();
        $variant->update(['size' => 'L', 'color' => 'Rouge', 'version' => 'Fan Version']);
        $this->assertSame('L', $variant->fresh()->size);
        $this->assertSame('Fan Version', $variant->fresh()->version);
        $this->assertSame('M', $variant->fresh()->attributeMirrorValue('size'));
        $this->assertSame('Player Version', $variant->fresh()->attributeMirrorValue('version'));
        $this->assertSame('Rouge', $variant->fresh()->attributeMirrorValue('color'));
        $this->assertNull($product->attributeMirrorValue('modele_compatible'));
        $this->assertNull($variant->attributeMirrorValue('cylindree'));
    }

    public static function parentChanges(): array
    {
        return [
            'sport vers moto frais' => ['sport', 'moto', false, 'M'],
            'sport vers moto chargé' => ['sport', 'moto', true, 'L'],
            'moto vers sport frais' => ['moto', 'sport', false, 'L'],
            'moto vers sport chargé' => ['moto', 'sport', true, null],
        ];
    }

    #[DataProvider('parentChanges')]
    public function test_constat_changement_parent_depend_du_cache_de_relation(string $from, string $to, bool $loaded, ?string $expectedMirror): void
    {
        $oldParent = Product::factory()->create(['activity' => $from]);
        $newParent = Product::factory()->create(['activity' => $to]);
        $created = ProductVariant::factory()->create([
            'product_id' => $oldParent->id, 'size' => 'M', 'color' => 'Bleu', 'stock' => 0,
        ]);
        // Instance distincte : la création a déjà pu charger sa relation product.
        $variant = ProductVariant::findOrFail($created->id);
        if ($loaded) {
            $variant->load('product');
        }
        $this->assertSame($loaded, $variant->relationLoaded('product'));
        $this->assertSame($from === 'sport' ? 'M' : null, $variant->attributeMirrorValue('size'));

        $variant->update(['product_id' => $newParent->id, 'size' => 'L', 'color' => 'Rouge']);
        $persisted = $variant->fresh();
        $this->assertSame($newParent->id, (int) $persisted->product_id);
        $this->assertSame($to, $persisted->product->activity);
        $this->assertSame('L', $persisted->size);
        $this->assertSame($expectedMirror, $persisted->attributeMirrorValue('size'));
        $this->assertSame('Rouge', $persisted->attributeMirrorValue('color'));
        $this->assertSame(0, $oldParent->variants()->count());
        $this->assertSame($variant->id, $newParent->variants()->sole()->id);
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
