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
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 2.6.7 — photographie des parcours historiques, pas contrat des futurs
 * formulaires dynamiques. Les limites nommées « constat » sont à arbitrer.
 * Aucun service VTC n'est représenté comme un produit dans ces fixtures.
 */
class ProductAttributeFormCharacterizationTest extends TestCase
{
    use RefreshDatabase;

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

    #[DataProvider('referenceContexts')]
    public function test_constat_creation_produit_prend_sport_meme_avec_categorie_moto(bool $seeded): void
    {
        $this->prepareReference($seeded);
        $category = Category::factory()->create(['name' => 'Moto', 'slug' => 'moto', 'activity' => 'moto']);
        Livewire::test(CreateProduct::class)
            ->assertFormFieldDoesNotExist('activity')
            ->fillForm([
                'reference' => 'CHAR-CREATE', 'nom' => 'Produit créé',
                'category_id' => $category->id, 'type' => 'Player Version',
                'taille' => 'M', 'equipe' => 'Equipe saisie',
                'prix_achat' => 10, 'prix_vente' => 20,
            ])->call('create')->assertHasNoFormErrors();

        $product = Product::where('reference', 'CHAR-CREATE')->sole();
        // Constat du défaut SQL et de l'absence de choix d'activité, pas cible métier.
        $this->assertSame('sport', $product->activity);
        $this->assertSame($category->id, (int) $product->category_id);
        $this->assertSame('M', $product->taille);
        $this->assertSame('Equipe saisie', $product->equipe);
        $this->assertSame($seeded ? 'M' : null, $product->attributeMirrorValue('taille'));
        $this->assertSame($seeded ? 'Equipe saisie' : null, $product->attributeMirrorValue('equipe'));
        $this->assertSame($seeded ? 2 : 0, $product->attributeValues()->count());
        $this->assertSame(0, $product->variants()->count());
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
