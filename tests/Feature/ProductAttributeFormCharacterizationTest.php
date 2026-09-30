<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\ProductVariants\Pages\CreateProductVariant;
use App\Filament\Resources\ProductVariants\Pages\EditProductVariant;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AttributeDefinitionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 2.6.7 — photographie des parcours historiques, pas contrat des futurs
 * formulaires dynamiques. Les limites nommées « constat » sont à arbitrer.
 * 2.6.9 — création avec activité explicite et catégorie compatible validées.
 * Aucun service VTC n'est représenté comme un produit dans ces fixtures.
 */
class ProductAttributeFormCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private const CATALOG_TABLES = [
        'products', 'product_variants', 'product_attribute_values', 'product_variant_attribute_values',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('manager'));
    }

    public static function commerceContexts(): array
    {
        $cases = [];
        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            foreach ([true, false] as $seeded) {
                $cases[$activity.($seeded ? ' avec référentiel' : ' sans référentiel')] = [$activity, $seeded];
            }
        }

        return $cases;
    }

    public static function referenceContexts(): array
    {
        return ['avec référentiel' => [true], 'sans référentiel' => [false]];
    }

    private function prepareReference(bool $seeded): void
    {
        if ($seeded) {
            $this->seed(AttributeDefinitionSeeder::class);
        }
        $this->assertDatabaseCount('attribute_definitions', $seeded ? 13 : 0);
    }

    private function makeProduct(string $activity): Product
    {
        $category = Category::factory()->create([
            'name' => 'Catalogue '.$activity, 'slug' => 'catalogue-'.$activity, 'activity' => $activity,
        ]);

        return Product::factory()->create([
            'activity' => $activity,
            'category_id' => $category->id,
            'type' => 'Player Version',
            'season' => null,
            'taille' => 'M',
            'equipe' => 'Equipe initiale',
            'stock' => 0,
        ]);
    }

    private function variantData(string $sku, string $size = 'M', string $color = 'Bleu', int $stock = 3): array
    {
        return [
            'sku' => $sku,
            'size' => $size,
            'color' => $color,
            'version' => 'Player Version',
            'stock' => $stock,
            'prix_achat' => 10,
            'prix_vente' => 20,
            'warehouse' => 'France',
            'status' => 'active',
        ];
    }

    private function assertVariantPersistence(ProductVariant $variant, string $activity, bool $seeded, string $size, string $color, int $stock): void
    {
        $variant->refresh();
        $this->assertSame($size, $variant->size);
        $this->assertSame($color, $variant->color);
        $this->assertSame('Player Version', $variant->version);
        $this->assertSame($stock, $variant->stock);
        $this->assertSame($seeded ? $color : null, $variant->attributeMirrorValue('color'));
        $this->assertSame($seeded && $activity === 'sport' ? $size : null, $variant->attributeMirrorValue('size'));
        $this->assertSame($seeded && $activity === 'sport' ? 'Player Version' : null, $variant->attributeMirrorValue('version'));
        $this->assertSame($seeded ? ($activity === 'sport' ? 3 : 1) : 0, $variant->attributeValues()->count());
        foreach (['taille_bebe', 'cylindree', 'dimension'] as $code) {
            $this->assertNull($variant->attributeMirrorValue($code));
        }
    }

    #[DataProvider('commerceContexts')]
    public function test_edition_produit_conserve_activite_et_ecrit_les_colonnes_historiques(string $activity, bool $seeded): void
    {
        $this->prepareReference($seeded);
        $product = $this->makeProduct($activity);

        $page = Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormFieldDoesNotExist('activity')
            ->assertFormFieldDoesNotExist('season');
        foreach (['tranche_age', 'modele_compatible', 'matiere', 'region_origine'] as $code) {
            $page->assertFormFieldDoesNotExist($code);
        }
        $page->fillForm(['taille' => 'L', 'equipe' => 'Equipe saisie'])
            ->call('save')->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame($activity, $product->activity);
        $this->assertSame('L', $product->taille);
        $this->assertSame('Equipe saisie', $product->equipe);
        $this->assertSame($seeded && $activity === 'sport' ? 'L' : null, $product->attributeMirrorValue('taille'));
        $this->assertSame($seeded && $activity === 'sport' ? 'Equipe saisie' : null, $product->attributeMirrorValue('equipe'));
        $this->assertSame($seeded && $activity === 'sport' ? 2 : 0, $product->attributeValues()->count());
        $this->assertSame(0, (int) $product->stock);
        $this->assertSame(0, $product->variants()->count());
    }

    public static function creationContexts(): array
    {
        $cases = [];
        foreach (self::commerceContexts() as $name => [$activity, $seeded]) {
            $cases[$name.' catégorie spécialisée'] = [$activity, $seeded, false];
            $cases[$name.' catégorie transverse'] = [$activity, $seeded, true];
        }

        return $cases;
    }

    private function productCreationData(int $categoryId): array
    {
        return [
            'reference' => 'CHAR-CREATE', 'nom' => 'Produit créé',
            'category_id' => $categoryId, 'type' => 'Player Version',
            'taille' => 'M', 'equipe' => 'Equipe saisie',
            'prix_achat' => 10, 'prix_vente' => 20,
            'variants' => ['nouvelle' => $this->variantData('CHAR-CREATE-VARIANT')],
        ];
    }

    #[DataProvider('creationContexts')]
    public function test_creation_produit_exige_activite_explicite_et_categorie_compatible(string $activity, bool $seeded, bool $transverse): void
    {
        $this->prepareReference($seeded);
        $category = Category::factory()->create([
            'name' => 'Catégorie création valide', 'slug' => 'char-creation-valide',
            'activity' => $transverse ? null : $activity,
        ]);
        Livewire::test(CreateProduct::class)
            ->assertFormSet(['activity' => null])
            ->assertFormFieldExists('activity', fn ($field): bool => $field->getOptions() === [
                'sport' => 'Sport', 'bebe' => 'Bébé', 'moto' => 'Moto', 'artisanat' => 'Artisanat',
            ])
            ->fillForm($this->productCreationData($category->id))
            ->assertFormSet(['activity' => null])
            ->fillForm(['activity' => $activity])
            ->call('create')->assertHasNoFormErrors();

        $product = Product::where('reference', 'CHAR-CREATE')->sole();
        $this->assertSame($activity, $product->activity);
        $this->assertSame($category->id, (int) $product->category_id);
        $this->assertSame('M', $product->taille);
        $this->assertSame('Equipe saisie', $product->equipe);
        $this->assertSame($seeded && $activity === 'sport' ? 'M' : null, $product->attributeMirrorValue('taille'));
        $this->assertSame($seeded && $activity === 'sport' ? 'Equipe saisie' : null, $product->attributeMirrorValue('equipe'));
        $this->assertSame($seeded && $activity === 'sport' ? 2 : 0, $product->attributeValues()->count());
        $variant = $product->variants()->sole();
        $this->assertVariantPersistence($variant, $activity, $seeded, 'M', 'Bleu', 3);
        $this->assertSame(3, (int) $product->fresh()->stock);
    }

    public static function invalidCreationContexts(): array
    {
        $cases = [];
        $invalid = [
            'activité absente' => [[], 'sport', 'activity'],
            'activité null' => [['activity' => null], null, 'activity'],
            'activité vide' => [['activity' => ''], null, 'activity'],
            'activité inconnue' => [['activity' => 'inconnue'], null, 'activity'],
            'activité VTC' => [['activity' => 'vtc'], null, 'activity'],
            'activité non textuelle' => [['activity' => ['sport']], null, 'activity'],
            'catégorie absente' => [['activity' => 'sport', 'category_id' => null], null, 'category_id'],
            'catégorie inexistante' => [['activity' => 'sport', 'category_id' => 999999], null, 'category_id'],
        ];
        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            foreach (['sport', 'bebe', 'moto', 'artisanat', 'vtc'] as $categoryActivity) {
                if ($activity !== $categoryActivity) {
                    $invalid[$activity.' avec catégorie '.$categoryActivity] = [
                        ['activity' => $activity], $categoryActivity, 'category_id',
                    ];
                }
            }
        }
        foreach ($invalid as $name => $case) {
            foreach ([true, false] as $seeded) {
                $cases[$name.($seeded ? ' avec référentiel' : ' sans référentiel')] = [...$case, $seeded];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidCreationContexts')]
    public function test_creation_invalide_ne_persiste_ni_produit_ni_variante(array $overrides, ?string $categoryActivity, string $error, bool $seeded): void
    {
        $this->prepareReference($seeded);
        $category = Category::factory()->create([
            'name' => 'Catégorie soumission invalide', 'slug' => 'char-soumission-invalide',
            'activity' => $categoryActivity,
        ]);
        Livewire::test(CreateProduct::class)
            ->fillForm(array_replace($this->productCreationData($category->id), $overrides))
            ->call('create')->assertHasFormErrors([$error]);
        $this->assertNoCreatedProductOrMirror();
    }

    public function test_categorie_revalidee_apres_selection_et_activite_jamais_deduite(): void
    {
        $this->prepareReference(true);
        $category = Category::factory()->create([
            'name' => 'Catégorie à revalider', 'slug' => 'char-categorie-revalidation',
            'activity' => 'moto',
        ]);
        $page = Livewire::test(CreateProduct::class)
            ->fillForm($this->productCreationData($category->id))
            ->assertFormSet(['activity' => null])
            ->fillForm(['activity' => 'moto']);
        $category->update(['activity' => 'sport']);
        $page->call('create')->assertHasFormErrors(['category_id']);
        $this->assertNoCreatedProductOrMirror();
    }

    public function test_creer_un_autre_ne_preselectionne_pas_activite(): void
    {
        $category = Category::factory()->create([
            'name' => 'Catégorie créer un autre', 'slug' => 'char-creer-un-autre',
            'activity' => null,
        ]);
        Livewire::test(CreateProduct::class)
            ->fillForm(['activity' => 'moto'] + $this->productCreationData($category->id))
            ->call('create', true)->assertHasNoFormErrors()
            ->assertFormSet(['activity' => null]);
        $this->assertSame('moto', Product::sole()->activity);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_edition_ignore_activite_injectee_et_ne_restreint_pas_categorie(): void
    {
        $this->prepareReference(true);
        $product = $this->makeProduct('moto');
        $category = Category::factory()->create([
            'name' => 'Catégorie édition Sport', 'slug' => 'char-edition-sport',
            'activity' => 'sport',
        ]);
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->assertFormFieldDoesNotExist('activity')
            ->fillForm(['category_id' => $category->id])
            ->set('data.activity', 'sport')
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('moto', $product->fresh()->activity);
        $this->assertSame($category->id, (int) $product->fresh()->category_id);
    }

    private function assertNoCreatedProductOrMirror(): void
    {
        foreach (['products', 'product_variants', 'product_attribute_values', 'product_variant_attribute_values'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    #[DataProvider('commerceContexts')]
    public function test_variante_imbriquee_creation_puis_edition_persistent_colonnes_miroirs_et_stock(string $activity, bool $seeded): void
    {
        $this->prepareReference($seeded);
        $product = $this->makeProduct($activity);
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['variants' => ['nouvelle' => $this->variantData('CHAR-NESTED')]])
            ->call('save')->assertHasNoFormErrors();

        $variant = $product->variants()->sole();
        $this->assertSame('CHAR-NESTED', $variant->sku);
        $this->assertVariantPersistence($variant, $activity, $seeded, 'M', 'Bleu', 3);
        $this->assertSame(3, (int) $product->fresh()->stock);

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['variants' => ['record-'.$variant->id => $this->variantData('CHAR-NESTED', 'L', 'Rouge', 7)]])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame($variant->id, $product->variants()->sole()->id);
        $this->assertSame('CHAR-NESTED', $variant->fresh()->sku);
        $this->assertVariantPersistence($variant, $activity, $seeded, 'L', 'Rouge', 7);
        $this->assertSame(7, (int) $product->fresh()->stock);
    }

    #[DataProvider('commerceContexts')]
    public function test_variante_autonome_creation_puis_edition_persistent_colonnes_miroirs_et_stock(string $activity, bool $seeded): void
    {
        $this->prepareReference($seeded);
        $product = $this->makeProduct($activity);
        $page = Livewire::test(CreateProductVariant::class);
        foreach (['taille_bebe', 'cylindree', 'dimension'] as $code) {
            $page->assertFormFieldDoesNotExist($code);
        }
        $page->fillForm(['product_id' => $product->id] + $this->variantData('CHAR-STANDALONE'))
            ->call('create')->assertHasNoFormErrors();
        $variant = $product->variants()->sole();
        $this->assertVariantPersistence($variant, $activity, $seeded, 'M', 'Bleu', 3);
        $this->assertSame(3, (int) $product->fresh()->stock);

        Livewire::test(EditProductVariant::class, ['record' => $variant->id])
            ->fillForm($this->variantData('CHAR-STANDALONE', 'L', 'Rouge', 7))
            ->call('save')->assertHasNoFormErrors();
        $this->assertVariantPersistence($variant, $activity, $seeded, 'L', 'Rouge', 7);
        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->assertSame($variant->id, $product->variants()->sole()->id);

        // Le refus du doublon ne doit créer ni variante, ni miroir, ni stock.
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $product->id] + $this->variantData('CHAR-STANDALONE'))
            ->call('create')->assertHasFormErrors(['sku']);
        $this->assertSame(1, $product->variants()->count());
        $this->assertVariantPersistence($variant, $activity, $seeded, 'L', 'Rouge', 7);
        $this->assertSame(7, (int) $product->fresh()->stock);
    }

    public static function invalidNestedIdentifiers(): array
    {
        return [
            'SKU absent' => [[], 'sku'],
            'SKU null' => [['sku' => null], 'sku'],
            'SKU vide' => [['sku' => ''], 'sku'],
            'SKU blanc' => [['sku' => '   '], 'sku'],
            'SKU trop long' => [['sku' => str_repeat('S', 256)], 'sku'],
            'barcode trop long' => [['sku' => 'VALID-SKU', 'barcode' => str_repeat('B', 256)], 'barcode'],
        ];
    }

    #[DataProvider('invalidNestedIdentifiers')]
    public function test_identifiant_imbrique_invalide_ne_persiste_rien(array $identifiers, string $field): void
    {
        $this->prepareReference(true);
        $invalid = $this->variantData('REMOVED');
        unset($invalid['sku']);
        $page = $this->nestedCreationPage('sport', [
            'valide' => $this->variantData('VALID-FIRST'),
            'invalide' => array_replace($invalid, $identifiers),
        ]);

        $this->assertRejectedWithoutCatalogWrites($page, 'create', ["variants.invalide.{$field}"]);
        $this->assertNoCreatedProductOrMirror();
    }

    public static function nestedDatabaseCollisions(): array
    {
        $cases = [];
        foreach (['create', 'edit'] as $operation) {
            foreach ([['sku', 'TAKEN-SKU'], ['barcode', 'TAKEN-BARCODE'], ['barcode', '0'], ['barcode', '   ']] as [$field, $value]) {
                $cases[$operation.' '.$field.' '.var_export($value, true)] = [$operation, $field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('nestedDatabaseCollisions')]
    public function test_identifiant_imbrique_deja_present_en_base_est_refuse(string $operation, string $field, string $value): void
    {
        $this->prepareReference(true);
        $otherProduct = $this->makeProduct('sport');
        $otherVariant = ProductVariant::factory()->create([
            'product_id' => $otherProduct->id, $field => $value, 'stock' => 4,
        ]);
        $row = array_replace($this->variantData('AVAILABLE-SKU'), [$field => $value]);

        if ($operation === 'create') {
            $page = $this->nestedCreationPage('moto', ['invalide' => $row]);
            $method = 'create';
            $path = "variants.invalide.{$field}";
        } else {
            $product = $this->makeProduct('moto');
            $variant = ProductVariant::factory()->create([
                'product_id' => $product->id, 'sku' => 'OWN-SKU', 'barcode' => 'OWN-BARCODE', 'stock' => 3,
            ]);
            // Un id injecté ne doit jamais permettre d'ignorer la variante concurrente.
            $row['id'] = $otherVariant->id;
            $page = Livewire::test(EditProduct::class, ['record' => $product->id])
                ->fillForm(['variants' => ['record-'.$variant->id => $row]]);
            $method = 'save';
            $path = 'variants.record-'.$variant->id.'.'.$field;
        }

        $this->assertRejectedWithoutCatalogWrites($page, $method, [$path]);
    }

    #[DataProvider('commerceContexts')]
    public function test_doublons_entre_nouvelles_variantes_sont_refuses_avant_creation(string $activity, bool $seeded): void
    {
        $this->prepareReference($seeded);
        $row = $this->variantData('DUPLICATE-SKU') + ['barcode' => 'DUPLICATE-BARCODE'];
        // Deux nouvelles clés avec les mêmes données, comme après clonage du repeater.
        $page = $this->nestedCreationPage($activity, ['originale' => $row, 'clone' => $row]);

        $this->assertRejectedWithoutCatalogWrites($page, 'create', [
            'variants.originale.sku', 'variants.clone.sku',
            'variants.originale.barcode', 'variants.clone.barcode',
        ]);
        $this->assertNoCreatedProductOrMirror();
    }

    public static function specialNestedDuplicates(): array
    {
        return [
            'SKU zero' => ['sku', '0'],
            'SKU numérique identique' => ['sku', '00123'],
            'barcode zero' => ['barcode', '0'],
            'barcode numérique identique' => ['barcode', '00123'],
            'barcode espaces' => ['barcode', '   '],
        ];
    }

    #[DataProvider('specialNestedDuplicates')]
    public function test_doublons_internes_des_identifiants_particuliers_sont_refuses(string $field, string $value): void
    {
        $this->prepareReference(true);
        $page = $this->nestedCreationPage('sport', [
            'a' => array_replace($this->variantData('SKU-A'), [$field => $value]),
            'b' => array_replace($this->variantData('SKU-B'), [$field => $value]),
        ]);

        $this->assertRejectedWithoutCatalogWrites($page, 'create', ["variants.a.{$field}", "variants.b.{$field}"]);
        $this->assertNoCreatedProductOrMirror();
    }

    public static function existingAndNewIdentifierCollisions(): array
    {
        return [
            'SKU persisté' => ['sku', false],
            'SKU nouvellement saisi' => ['sku', true],
            'barcode persisté' => ['barcode', false],
            'barcode nouvellement saisi' => ['barcode', true],
        ];
    }

    #[DataProvider('existingAndNewIdentifierCollisions')]
    public function test_collision_entre_variante_existante_et_nouvelle_est_refusee(string $field, bool $changed): void
    {
        $this->prepareReference(true);
        $product = $this->makeProduct('sport');
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'OWN-SKU', 'barcode' => 'OWN-BARCODE', 'stock' => 3,
        ]);
        $value = $changed ? 'NEW-COMMON-VALUE' : $variant->getAttribute($field);
        $page = Livewire::test(EditProduct::class, ['record' => $product->id])->fillForm([
            'variants' => [
                'record-'.$variant->id => array_replace(
                    $this->variantData('OWN-SKU') + ['barcode' => 'OWN-BARCODE'], [$field => $value],
                ),
                'nouvelle' => array_replace($this->variantData('NEW-SKU'), [$field => $value]),
            ],
        ]);

        $this->assertRejectedWithoutCatalogWrites($page, 'save', [
            'variants.record-'.$variant->id.'.'.$field, 'variants.nouvelle.'.$field,
        ]);
    }

    #[DataProvider('commerceContexts')]
    public function test_identifiants_valides_sont_conserves_en_creation_et_edition(string $activity, bool $seeded): void
    {
        $this->prepareReference($seeded);
        $identifiers = [
            ['sku' => 'ABSENT-A', 'barcode' => null],
            ['sku' => 'ABSENT-B', 'barcode' => null],
            ['sku' => 'EMPTY-A', 'barcode' => ''],
            ['sku' => 'EMPTY-B', 'barcode' => ''],
            ['sku' => '00123', 'barcode' => '00123'],
            ['sku' => '123', 'barcode' => '123'],
            ['sku' => '0', 'barcode' => '0'],
            ['sku' => 'SPACES-A', 'barcode' => '   '],
            ['sku' => 'SPACES-B', 'barcode' => '  '],
            ['sku' => ' AbC ', 'barcode' => ' AbC '],
            ['sku' => 'AbC', 'barcode' => 'AbC'],
            ['sku' => 'abc', 'barcode' => 'abc'],
            ['sku' => str_repeat('S', 255), 'barcode' => str_repeat('B', 255)],
        ];
        $rows = [];
        foreach ($identifiers as $index => $identifier) {
            $rows['new-'.$index] = $this->variantData($identifier['sku']) + ['barcode' => $identifier['barcode']];
        }
        $this->nestedCreationPage($activity, $rows)->call('create')->assertHasNoFormErrors();

        $product = Product::where('reference', 'CHAR-CREATE')->sole();
        $this->assertSame($activity, $product->activity);
        $this->assertSame($activity, $product->category->activity);
        $this->assertCount(count($identifiers), $product->variants()->get());
        $this->assertSame(count($identifiers) * 3, (int) $product->fresh()->stock);
        $this->assertSame($seeded && $activity === 'sport' ? 'M' : null, $product->attributeMirrorValue('taille'));
        $this->assertSame($seeded && $activity === 'sport' ? 'Equipe saisie' : null, $product->attributeMirrorValue('equipe'));

        $edition = [];
        $ids = [];
        foreach ($identifiers as $identifier) {
            $variant = $product->variants()->where('sku', $identifier['sku'])->sole();
            $ids[] = $variant->id;
            $this->assertSame($identifier['sku'], $variant->sku);
            $this->assertSame($identifier['barcode'] === '' ? null : $identifier['barcode'], $variant->barcode);
            $this->assertVariantPersistence($variant, $activity, $seeded, 'M', 'Bleu', 3);
            $edition['record-'.$variant->id] = $this->variantData($identifier['sku'], 'L', 'Rouge', 7)
                + ['barcode' => $identifier['barcode']];
        }

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['variants' => $edition])->call('save')->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing($ids, $product->variants()->pluck('id')->all());
        $this->assertSame(count($identifiers) * 7, (int) $product->fresh()->stock);
        foreach ($identifiers as $identifier) {
            $variant = $product->variants()->where('sku', $identifier['sku'])->sole();
            $this->assertSame($identifier['sku'], $variant->sku);
            $this->assertSame($identifier['barcode'] === '' ? null : $identifier['barcode'], $variant->barcode);
            $this->assertVariantPersistence($variant, $activity, $seeded, 'L', 'Rouge', 7);
        }
    }

    #[DataProvider('commerceContexts')]
    public function test_edition_invalide_empeche_modification_suppression_et_creation(string $activity, bool $seeded): void
    {
        $this->prepareReference($seeded);
        $product = $this->makeProduct($activity);
        $modified = $product->variants()->create($this->variantData('EXISTING-MODIFIED'));
        $deleted = $product->variants()->create($this->variantData('EXISTING-DELETED'));
        $unchanged = $product->variants()->create($this->variantData('EXISTING-UNCHANGED'));
        $this->assertSame(9, (int) $product->fresh()->stock);
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $before = $this->catalogSnapshot();

        $this->travel(2)->seconds();
        try {
            $page->fillForm([
                'nom' => 'Nom à ne pas sauvegarder', 'taille' => 'XL', 'equipe' => 'Equipe à ne pas sauvegarder',
                'variants' => [
                    'record-'.$modified->id => $this->variantData('EXISTING-MODIFIED', 'L', 'Rouge', 99),
                    // L'absence de record-<deleted> demande une suppression lors de saveRelationships.
                    'record-'.$unchanged->id => $this->variantData('EXISTING-UNCHANGED'),
                    'valide' => $this->variantData('NEW-VALID'),
                    'invalide' => $this->variantData('EXISTING-UNCHANGED'),
                ],
            ]);
            // Remplacer le tableau entier garantit que la clé supprimée ne subsiste pas après fillForm.
            $rows = $page->get('data.variants');
            unset($rows['record-'.$deleted->id]);
            $page->set('data.variants', $rows);
            $this->assertArrayNotHasKey('record-'.$deleted->id, $page->get('data.variants'));
            $this->assertSame($before, $this->catalogSnapshot());

            $this->assertRejectedWithoutCatalogWrites($page, 'save', [
                'variants.record-'.$unchanged->id.'.sku', 'variants.invalide.sku',
            ]);
            $this->assertSame($before, $this->catalogSnapshot());
        } finally {
            $this->travelBack();
        }
    }

    private function nestedCreationPage(string $activity, array $variants): Testable
    {
        $category = Category::factory()->create([
            'name' => 'Catégorie identifiants', 'slug' => 'nested-identifiers', 'activity' => $activity,
        ]);

        return Livewire::test(CreateProduct::class)->fillForm(array_replace(
            $this->productCreationData($category->id), ['activity' => $activity, 'variants' => $variants],
        ));
    }

    private function catalogSnapshot(): array
    {
        $snapshot = [];
        foreach (self::CATALOG_TABLES as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function assertRejectedWithoutCatalogWrites(Testable $page, string $method, array $errors): void
    {
        $before = $this->catalogSnapshot();
        $connection = DB::connection();
        $wasLogging = $connection->logging();
        $offset = count($connection->getQueryLog());
        $connection->enableQueryLog();

        try {
            $page->call($method)->assertHasFormErrors($errors);
            $queries = array_slice($connection->getQueryLog(), $offset);
        } finally {
            if (! $wasLogging) {
                $connection->disableQueryLog();
                $connection->flushQueryLog();
            }
        }

        $writes = array_filter($queries, static fn (array $query): bool =>
            preg_match('/^\s*(?:insert|update|delete|replace)\b/i', $query['query']) === 1
            && preg_match('/\b(?:products|product_variants|product_attribute_values|product_variant_attribute_values)\b/i', $query['query']) === 1);
        $this->assertSame([], array_values($writes), 'Aucune écriture catalogue ne doit précéder le rejet de validation.');
        $this->assertSame($before, $this->catalogSnapshot());
    }

    public function test_viewer_ne_peut_pas_ouvrir_les_parcours_de_mutation(): void
    {
        $this->prepareReference(true);
        $product = $this->makeProduct('moto');
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'stock' => 3]);
        $beforeProduct = $product->fresh()->getAttributes();
        $beforeVariant = $variant->fresh()->getAttributes();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(CreateProduct::class)->assertForbidden();
        Livewire::test(EditProduct::class, ['record' => $product->id])->assertForbidden();
        Livewire::test(CreateProductVariant::class)->assertForbidden();
        Livewire::test(EditProductVariant::class, ['record' => $variant->id])->assertForbidden();

        $this->assertSame($beforeProduct, $product->fresh()->getAttributes());
        $this->assertSame($beforeVariant, $variant->fresh()->getAttributes());
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
    }
}
