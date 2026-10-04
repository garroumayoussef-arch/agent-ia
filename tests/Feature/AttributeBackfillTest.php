<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\Category;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function historicalSources(): array
    {
        return ['taille vide' => [''], 'taille zero' => ['0'], 'valeur brute' => ['  M  ']];
    }

    #[DataProvider('historicalSources')]
    public function test_backfill_preserves_historical_sources_without_source_updates(string $taille): void
    {
        $category = Category::factory()->create(['name' => 'Nom actuel', 'slug' => 'backfill-historique']);
        $product = Product::factory()->create(['activity' => 'sport', 'category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        // Préparer les sources historiques en SQL pour ne pas déclencher saving/saved.
        DB::table('products')->where('id', $product->id)->update([
            'categorie' => 'Nom historique', 'marque' => 'Marque historique',
            'fournisseur' => 'Fournisseur historique', 'taille' => $taille,
            'equipe' => '  Equipe  ', 'season' => '0', 'stock' => 37,
            'updated_at' => '2020-01-02 03:04:05',
        ]);
        DB::table('product_variants')->where('id', $variant->id)->update([
            'size' => '0', 'color' => '  Bleu  ', 'version' => '', 'stock' => 5,
            'updated_at' => '2020-02-03 04:05:06',
        ]);
        ProductAttributeValue::query()->delete();
        ProductVariantAttributeValue::query()->delete();
        $before = $this->sourceSnapshot();
        $connection = DB::connection();
        $originalLog = $connection->getQueryLog();
        $wasLogging = $connection->logging();
        $connection->enableQueryLog();
        $this->travel(1)->hour();
        try {
            foreach ([1, 2] as $run) {
                $this->assertSame(0, Artisan::call('attributes:backfill'));
                $output = Artisan::output();
                $this->assertStringContainsString('Produits traités : 1', $output);
                $this->assertStringContainsString('Variantes traitées : 1', $output);
                $this->assertSame($before, $this->sourceSnapshot(), 'Sources et timestamps relus avant teardown.');
                foreach (['season' => '0', 'taille' => $taille, 'equipe' => '  Equipe  '] as $code => $value) {
                    $this->assertSame($value, $product->fresh()->attributeMirrorValue($code));
                }
                foreach (['size' => '0', 'color' => '  Bleu  ', 'version' => ''] as $code => $value) {
                    $this->assertSame($value, $variant->fresh()->attributeMirrorValue($code));
                }
                $this->assertSame(3, ProductAttributeValue::count());
                $this->assertSame(3, ProductVariantAttributeValue::count());
            }
            $queries = array_slice($connection->getQueryLog(), count($originalLog));
            $writes = array_filter($queries, static fn (array $query): bool =>
                preg_match('/^\s*(?:update|insert into|delete from|replace into)\s+(?:products|product_variants)\b/i', str_replace(['"', '`'], '', $query['query'])) === 1
            );
            $this->assertSame([], array_values($writes), 'Aucune écriture des sources, même temporaire.');
        } finally {
            (new \ReflectionProperty($connection, 'queryLog'))->setValue($connection, $originalLog);
            $wasLogging ? $connection->enableQueryLog() : $connection->disableQueryLog();
            $this->travelBack();
        }
    }

    private function sourceSnapshot(): array
    {
        $snapshot = [];
        foreach (['products', 'product_variants'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    public function test_backfill_preserves_null_and_inapplicable_mirrors(): void
    {
        $product = Product::factory()->create(['activity' => 'sport', 'season' => 'Ancienne saison']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'M', 'color' => 'Rouge']);
        $beforeProductMirrors = DB::table('product_attribute_values')->orderBy('id')->get()->all();
        $beforeVariantMirrors = DB::table('product_variant_attribute_values')->orderBy('id')->get()->all();
        DB::table('products')->where('id', $product->id)->update(['activity' => 'moto', 'season' => null]);
        DB::table('product_variants')->where('id', $variant->id)->update(['size' => 'XL', 'color' => null, 'version' => null]);
        $before = $this->sourceSnapshot();
        $this->assertSame(0, Artisan::call('attributes:backfill'));
        $this->assertSame($before, $this->sourceSnapshot());
        $this->assertEquals($beforeProductMirrors, DB::table('product_attribute_values')->orderBy('id')->get()->all());
        $this->assertEquals($beforeVariantMirrors, DB::table('product_variant_attribute_values')->orderBy('id')->get()->all());
        $this->assertSame('M', $variant->fresh()->attributeMirrorValue('size'));
        $this->assertSame('Rouge', $variant->fresh()->attributeMirrorValue('color'));
    }

    public function test_normal_saves_still_dual_write_after_backfill(): void
    {
        $product = Product::factory()->create(['activity' => 'sport', 'taille' => 'M']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'size' => 'M']);
        Artisan::call('attributes:backfill');
        $product->update(['season' => 'Nouvelle saison', 'taille' => 'L', 'equipe' => 'Nouvelle equipe']);
        $variant->update(['size' => 'XL', 'color' => 'Vert', 'version' => 'Player Version', 'stock' => 9]);
        foreach (['season' => 'Nouvelle saison', 'taille' => 'L', 'equipe' => 'Nouvelle equipe'] as $code => $value) {
            $this->assertSame($value, $product->fresh()->attributeMirrorValue($code));
        }
        foreach (['size' => 'XL', 'color' => 'Vert', 'version' => 'Player Version'] as $code => $value) {
            $this->assertSame($value, $variant->fresh()->attributeMirrorValue($code));
        }
        $this->assertSame(9, (int) $product->fresh()->stock);
    }
}
