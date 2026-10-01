<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\ProductVariants\Pages\CreateProductVariant;
use App\Filament\Resources\ProductVariants\Pages\EditProductVariant;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Database\Seeders\AttributeDefinitionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Builder;
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

    public static function invalidStandaloneBarcodes(): array
    {
        $cases = [];
        foreach (['create', 'edit'] as $operation) {
            foreach ([
                'collision espaces' => ['   ', true],
                'collision ordinaire' => ['TAKEN-BARCODE', true],
                'espaces trop longs' => [str_repeat(' ', 256), false],
                'texte trop long' => [str_repeat('B', 256), false],
            ] as $name => [$barcode, $collision]) {
                $cases[$operation.' '.$name] = [$operation, $barcode, $collision];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidStandaloneBarcodes')]
    public function test_barcode_autonome_invalide_est_rejete_avant_toute_ecriture(string $operation, string $barcode, bool $collision): void
    {
        $this->prepareReference(true);
        $product = $this->makeProduct('sport');
        // Meme parent : ignorer toutes ses variantes masquerait la collision.
        $other = $product->variants()->create($this->variantData('BARCODE-OTHER') + [
            'barcode' => $collision ? $barcode : 'OTHER-BARCODE',
        ]);
        $data = $this->variantData('BARCODE-CANDIDATE', 'L', 'Rouge', 99) + [
            'product_id' => $product->id, 'barcode' => $barcode,
        ];
        if ($operation === 'create') {
            $page = Livewire::test(CreateProductVariant::class)->fillForm($data);
            $method = 'create';
        } else {
            $current = $product->variants()->create($this->variantData('BARCODE-CANDIDATE') + ['barcode' => 'OWN-BARCODE']);
            $page = Livewire::test(EditProductVariant::class, ['record' => $current->id])->fillForm($data);
            // Une cle soumise ne doit pas remplacer l'identite persistante du record courant.
            $page->set('data.id', $other->id);
            $method = 'save';
        }

        $this->travel(2)->seconds();
        try {
            $this->assertRejectedWithoutCatalogWrites($page, $method, ['barcode']);
        } finally {
            $this->travelBack();
        }
    }

    public static function validStandaloneBarcodes(): array
    {
        $cases = [];
        foreach ([
            'null' => null,
            'vide' => '',
            'zero' => '0',
            'zeros initiaux' => '00123',
            'numerique' => '123',
            'ordinaire' => 'BARCODE-VALID',
            'casse et espaces conserves' => ' AbC ',
            'espaces' => '   ',
            'blancs Laravel' => " \t\r\n ",
            'texte limite 255' => str_repeat('B', 255),
            'espaces limite 255' => str_repeat(' ', 255),
        ] as $name => $barcode) {
            $cases[$name] = ['sport', true, $barcode];
        }
        foreach (self::commerceContexts() as $name => [$activity, $seeded]) {
            if ($activity !== 'sport' || ! $seeded) {
                $cases[$name.' espaces'] = [$activity, $seeded, '   '];
            }
        }

        return $cases;
    }

    #[DataProvider('validStandaloneBarcodes')]
    public function test_barcode_autonome_valide_est_conserve_exactement_en_creation_et_edition(string $activity, bool $seeded, ?string $barcode): void
    {
        $this->prepareReference($seeded);
        $product = $this->makeProduct($activity);
        // Prouver les absences multiples et la distinction 00123/123 dans la meme base.
        $otherBarcode = match ($barcode) {
            '00123' => '123',
            '123' => '00123',
            default => null,
        };
        $other = $product->variants()->create($this->variantData('BARCODE-CONTROL', 'M', 'Bleu', 4)
            + ['barcode' => $otherBarcode]);
        $otherBefore = $other->fresh()->getAttributes();
        $expected = $barcode === '' ? null : $barcode;

        Livewire::test(CreateProductVariant::class)
            ->fillForm($this->variantData('BARCODE-VALID') + ['product_id' => $product->id, 'barcode' => $barcode])
            ->call('create')->assertHasNoFormErrors();
        $variant = $product->variants()->where('sku', 'BARCODE-VALID')->sole();
        $this->assertSame($expected, $variant->barcode);
        $this->assertVariantPersistence($variant, $activity, $seeded, 'M', 'Bleu', 3);
        $this->assertSame(7, (int) $product->fresh()->stock);

        Livewire::test(EditProductVariant::class, ['record' => $variant->id])
            ->fillForm($this->variantData('BARCODE-VALID', 'L', 'Rouge', 7) + ['barcode' => $barcode])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($variant->id, $product->variants()->where('sku', 'BARCODE-VALID')->sole()->id);
        $this->assertSame($expected, $variant->fresh()->barcode);
        $this->assertVariantPersistence($variant, $activity, $seeded, 'L', 'Rouge', 7);
        $this->assertSame(11, (int) $product->fresh()->stock);
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->assertSame(2, $product->variants()->count());
    }

    public static function lateDeletionProtections(): array
    {
        return [
            'achat avec definitions' => ['purchase', true],
            'vente avec definitions' => ['sale', true],
            'mouvement avec definitions' => ['movement', true],
            'achat sans definitions' => ['purchase', false],
        ];
    }

    #[DataProvider('lateDeletionProtections')]
    public function test_suppression_tardive_refusee_annule_les_ecritures_deja_executees(string $protection, bool $seeded): void
    {
        $this->prepareReference($seeded);
        $product = $this->makeProduct('sport');
        $removed = $product->variants()->create($this->variantData('ROLLBACK-FIRST'));
        $protected = $product->variants()->create($this->variantData('ROLLBACK-PROTECTED', 'L', 'Rouge', 7));
        $retained = $product->variants()->create($this->variantData('ROLLBACK-RETAINED', 'M', 'Bleu', 4));
        $this->assertLessThan($protected->id, $removed->id);

        $message = $this->createDeletionProtection($product, $protected, $protection);
        $this->assertSame($protection === 'purchase' ? 1 : 0, $protected->purchaseOrderItems()->count());
        $this->assertSame($protection === 'sale' ? 1 : 0, $protected->salesOrderItems()->count());
        $this->assertSame($protection === 'movement' ? 1 : 0, $protected->stockMovements()->count());
        $this->assertSame($seeded ? 3 : 0, $removed->attributeValues()->count());

        $connection = DB::connection();
        $this->assertEditModelsUseDefaultConnection();
        $initialLevel = $connection->transactionLevel();
        // Les effets de StockMovement appartiennent aux fixtures, avant le snapshot.
        $initialStock = (int) $product->fresh()->stock;
        $before = $this->editTransactionSnapshot();
        $scopes = ProductVariant::getAllGlobalScopes();
        $wasLogging = $connection->logging();
        $offset = count($connection->getQueryLog());
        $caught = null;
        $queries = [];

        $this->withoutExceptionHandling();
        $this->travel(2)->seconds();
        try {
            // Le DELETE du repeater ne definit aucun ordre : ce scope est local au test.
            ProductVariant::addGlobalScope('checkpoint_2613_delete_order', static function (Builder $query): void {
                $query->orderBy('product_variants.id');
            });
            $page = Livewire::test(EditProduct::class, ['record' => $product->id])
                ->fillForm(['nom' => 'Nom non persiste', 'taille' => 'XL', 'equipe' => 'Equipe non persistee'])
                ->set('data.variants', [
                    'record-'.$retained->id => $this->variantData('ROLLBACK-RETAINED', 'XL', 'Vert', 99),
                    'nouvelle' => $this->variantData('ROLLBACK-NEW'),
                ]);
            $this->assertArrayNotHasKey('record-'.$removed->id, $page->get('data.variants'));
            $this->assertArrayNotHasKey('record-'.$protected->id, $page->get('data.variants'));
            $this->assertSame($before, $this->editTransactionSnapshot());

            $offset = count($connection->getQueryLog());
            $connection->enableQueryLog();
            try {
                $page->call('save');
            } catch (\Exception $exception) {
                $caught = $exception;
            }
            $queries = array_slice($connection->getQueryLog(), $offset);
        } finally {
            ProductVariant::setAllGlobalScopes($scopes);
            if (! $wasLogging) {
                $connection->disableQueryLog();
                if ($offset === 0) {
                    $connection->flushQueryLog();
                }
            }
            $this->travelBack();
            $this->withExceptionHandling();
        }

        $this->assertNotNull($caught, 'La protection historique doit interrompre la sauvegarde.');
        $this->assertSame(\Exception::class, $caught::class);
        $this->assertSame($message, $caught->getMessage());
        $this->assertSame($initialLevel, $connection->transactionLevel());
        $this->assertDeletionQueriesBeforeRollback(
            $queries, $product->id, $removed->id, $protected->id,
            $initialStock - $removed->stock, $protection,
        );
        // Lectures DB neuves : aucune garantie n'est demandee aux objets Livewire/PHP.
        $this->assertSame($before, $this->editTransactionSnapshot());
        $this->assertSame($initialStock, (int) $product->fresh()->stock);
        $this->assertSame($seeded ? 3 : 0, $removed->fresh()->attributeValues()->count());
        $this->assertDatabaseMissing('product_variants', ['sku' => 'ROLLBACK-NEW']);
    }

    private function createDeletionProtection(Product $product, ProductVariant $variant, string $protection): string
    {
        if ($protection === 'purchase') {
            $order = PurchaseOrder::create(['reference' => 'ROLLBACK-PURCHASE']);
            PurchaseOrderItem::create([
                'purchase_order_id' => $order->id, 'product_id' => $product->id,
                'product_variant_id' => $variant->id, 'quantity_ordered' => 3,
            ]);

            return 'Impossible de supprimer cette variante : elle est référencée dans au moins un bon de commande fournisseur.';
        }

        if ($protection === 'sale') {
            $order = SalesOrder::create(['reference' => 'ROLLBACK-SALE']);
            SalesOrderItem::create([
                'sales_order_id' => $order->id, 'product_id' => $product->id,
                'product_variant_id' => $variant->id, 'quantity_ordered' => 2,
            ]);

            return 'Impossible de supprimer cette variante : elle est référencée dans au moins une commande client.';
        }

        $this->assertSame('movement', $protection);
        $warehouse = Warehouse::create(['name' => 'Entrepot transaction', 'code' => 'transaction', 'is_default' => true]);
        StockMovement::create([
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 5,
        ]);

        return 'Impossible de supprimer cette variante : elle possède un historique de mouvements de stock. Passez-la plutôt en rupture de stock ou inactive.';
    }

    private function assertDeletionQueriesBeforeRollback(
        array $queries, int $productId, int $removedId, int $protectedId, int $remainingStock, string $protection,
    ): void {
        $orderedSelection = $deletion = $stockUpdate = $historyCheck = null;
        $deletions = [];
        $historyTable = match ($protection) {
            'purchase' => 'purchase_order_items',
            'sale' => 'sales_order_items',
            'movement' => 'stock_movements',
        };

        foreach ($queries as $index => $query) {
            $sql = strtolower(str_replace(['"', '`'], '', $query['query']));
            $bindings = $query['bindings'];
            if (str_starts_with($sql, 'select * from product_variants ')
                && str_contains($sql, ' in (')
                && str_contains($sql, 'order by product_variants.id asc')) {
                $orderedSelection = $index;
            }
            if (str_starts_with($sql, 'delete from product_variants ')) {
                $deletions[] = $bindings;
                if ($bindings === [$removedId]) {
                    $deletion = $index;
                }
            }
            if (str_starts_with($sql, 'update products set ')
                && preg_match('/\bstock\s*=\s*\?/', $sql, $match, PREG_OFFSET_CAPTURE) === 1) {
                $stockBinding = substr_count(substr($sql, 0, $match[0][1]), '?');
                $this->assertSame($remainingStock, (int) $bindings[$stockBinding]);
                $this->assertSame($productId, (int) $bindings[array_key_last($bindings)]);
                $stockUpdate = $index;
            }
            if (str_starts_with($sql, 'select exists(')
                && str_contains($sql, 'from '.$historyTable.' ')
                && in_array($protectedId, $bindings, true)) {
                $historyCheck = $index;
            }
        }

        $this->assertNotNull($orderedSelection, 'La selection effective des suppressions doit etre ordonnee.');
        $this->assertNotNull($deletion, 'Le journal des requetes reussies doit contenir le premier DELETE.');
        $this->assertNotNull($stockUpdate, 'Le hook deleted doit avoir ecrit le stock recalcule.');
        $this->assertNotNull($historyCheck, 'La protection tardive doit consulter le vrai historique.');
        $this->assertSame([[$removedId]], $deletions, 'Seule la premiere variante doit atteindre le DELETE.');
        $this->assertTrue($orderedSelection < $deletion && $deletion < $stockUpdate && $stockUpdate < $historyCheck);
    }

    #[DataProvider('referenceContexts')]
    public function test_edition_transactionnelle_mixte_persiste_suppression_modification_creation_et_miroirs(bool $seeded): void
    {
        $this->prepareReference($seeded);
        $product = $this->makeProduct('sport');
        $removed = $product->variants()->create($this->variantData('MIXED-REMOVED'));
        $retained = $product->variants()->create($this->variantData('MIXED-RETAINED', 'M', 'Bleu', 4));
        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->assertSame($seeded ? 3 : 0, $removed->attributeValues()->count());
        $this->assertEditModelsUseDefaultConnection();
        $initialLevel = DB::connection()->transactionLevel();

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['nom' => 'Produit mixte sauvegarde', 'taille' => 'XL', 'equipe' => 'Equipe mixte'])
            ->set('data.variants', [
                'record-'.$retained->id => $this->variantData('MIXED-RETAINED', 'L', 'Rouge', 7),
                'nouvelle' => $this->variantData('MIXED-NEW', 'XL', 'Vert', 5),
            ])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame($initialLevel, DB::connection()->transactionLevel());
        $this->assertDatabaseMissing('product_variants', ['id' => $removed->id]);
        $this->assertDatabaseMissing('product_variant_attribute_values', ['product_variant_id' => $removed->id]);
        $created = $product->variants()->where('sku', 'MIXED-NEW')->sole();
        $this->assertNotContains($created->id, [$removed->id, $retained->id]);
        $this->assertEqualsCanonicalizing([$retained->id, $created->id], $product->variants()->pluck('id')->all());
        $this->assertSame('MIXED-RETAINED', $retained->fresh()->sku);
        $this->assertVariantPersistence($retained, 'sport', $seeded, 'L', 'Rouge', 7);
        $this->assertVariantPersistence($created, 'sport', $seeded, 'XL', 'Vert', 5);
        $product->refresh();
        $this->assertSame('Produit mixte sauvegarde', $product->nom);
        $this->assertSame('XL', $product->taille);
        $this->assertSame('Equipe mixte', $product->equipe);
        $this->assertSame(12, (int) $product->stock);
        $this->assertSame($seeded ? 'XL' : null, $product->attributeMirrorValue('taille'));
        $this->assertSame($seeded ? 'Equipe mixte' : null, $product->attributeMirrorValue('equipe'));
    }

    private function assertEditModelsUseDefaultConnection(): void
    {
        foreach ([
            Product::class, ProductVariant::class, ProductAttributeValue::class, ProductVariantAttributeValue::class,
            PurchaseOrder::class, PurchaseOrderItem::class, SalesOrder::class, SalesOrderItem::class,
            StockMovement::class, WarehouseStock::class,
        ] as $model) {
            $this->assertSame(DB::connection(), (new $model)->getConnection(), $model);
        }
    }

    private function editTransactionSnapshot(): array
    {
        $snapshot = $this->catalogSnapshot();
        foreach ([
            'purchase_orders', 'purchase_order_items', 'sales_orders', 'sales_order_items',
            'stock_movements', 'warehouse_stocks',
        ] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
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
