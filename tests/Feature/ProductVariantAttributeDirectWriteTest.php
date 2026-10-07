<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\CategoryAttributeDefinition;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Services\AttributeApplicabilityResolver;
use App\Services\AttributeDirectWriteRegistry;
use App\Services\ProductVariantAttributeWriteService;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Reuses the isolated 2.6.27 harness, providers and full Product regression matrix. */
class ProductVariantAttributeDirectWriteTest extends ProductAttributeDirectWriteTest
{
    protected ProductVariantAttributeWriteService $variantService;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_08_12_213348_create_product_variants_table.php',
            '2026_09_02_100004_create_product_variant_attribute_values_table.php'] as $file) {
            (require dirname(__DIR__, 2).'/database/migrations/'.$file)->up();
        }
        $this->variantService = new ProductVariantAttributeWriteService(
            new AttributeApplicabilityResolver, new AttributeDirectWriteRegistry($this->config), $this->service,
        );
    }

    protected function variantDefinition(array $overrides = [], bool $admit = true): AttributeDefinition
    {
        $definition = $this->definition(array_replace(['level' => 'variant'], $overrides), false);
        if ($admit) {
            $codes = $this->config->get('attribute_writing.variant_direct');
            $codes[] = $definition->code;
            $this->config->set('attribute_writing.variant_direct', $codes);
        }

        return $definition;
    }

    protected function variant(): ProductVariant
    {
        $parent = $this->product();
        $parent->save();

        return ProductVariant::create(['product_id' => $parent->id,
            'sku' => 'fixture-'.bin2hex(random_bytes(6)), 'barcode' => null,
            'size' => 'M', 'color' => 'Fixture', 'version' => 'Fixture',
            'stock' => 5, 'prix_achat' => '2.50', 'prix_vente' => '5.00']);
    }

    protected function writeVariant(ProductVariant $variant, array $values): void
    {
        $this->variantService->save($variant, $values, $this->variantService->prepare($variant));
    }

    protected function rows(): array
    {
        $rows = parent::rows();
        foreach (['product_variants', 'product_variant_attribute_values'] as $table) {
            $rows[$table] = $this->connection->table($table)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    public function test_variant_defaults_empty_and_admission_is_separate(): void
    {
        $this->assertSame([], $this->config->get('attribute_writing.direct'));
        $this->assertSame([], $this->config->get('attribute_writing.variant_direct'));
        $definition = $this->variantDefinition([], false);
        $variant = $this->variant();
        $this->rejected(fn () => $this->writeVariant($variant, ['fixture_text' => 'value']));
        $this->assertSame([], $this->variantService->activeValues($variant));
        $this->config->set('attribute_writing.direct', [$definition->code]);
        $this->rejected(fn () => $this->writeVariant($variant, ['fixture_text' => 'value']));
        $this->config->set('attribute_writing.direct', []);
        $this->config->set('attribute_writing.variant_direct', [$definition->code]);
        $this->writeVariant($variant, ['fixture_text' => 'value']);
        $this->assertSame(['fixture_text' => 'value'], $this->variantService->activeValues($variant));
        $parent = $variant->product()->first();
        $this->rejected(fn () => $this->service->save($parent, ['fixture_text' => 'value'], $this->service->prepare($parent)));
    }

    #[DataProvider('values')]
    public function test_variant_text_and_empty_semantics(mixed $value, ?string $expected): void
    {
        $this->variantDefinition();
        $variant = $this->variant();
        $this->writeVariant($variant, ['fixture_text' => $value]);
        $this->assertSame($expected === null ? [] : ['fixture_text' => $expected],
            $this->variantService->activeValues($variant));
        $this->assertSame($expected === null ? 0 : 1, ProductVariantAttributeValue::count());
        $this->writeVariant($variant, ['fixture_text' => 'existing']);
        $this->writeVariant($variant, ['fixture_text' => $value]);
        $this->assertSame($expected, ProductVariantAttributeValue::value('value'));
    }

    #[DataProvider('invalidValues')]
    public function test_variant_invalid_values_never_write(string $type, mixed $value): void
    {
        $this->variantDefinition(['input_type' => $type, 'options' => $type === 'select' ? ['M', 'É', '0'] : null]);
        $variant = $this->variant();
        $this->rejected(fn () => $this->writeVariant($variant, ['fixture_text' => $value]));
    }

    public function test_variant_select_exact_and_unicode_limit(): void
    {
        $long = str_repeat('é', 255);
        $this->variantDefinition(['input_type' => 'select', 'options' => ['É', '0', $long]]);
        $variant = $this->variant();
        foreach (['É', '0', $long] as $value) {
            $this->writeVariant($variant, ['fixture_text' => $value]);
            $this->assertSame(['fixture_text' => $value], $this->variantService->activeValues($variant));
        }
        $this->writeVariant($variant, ['fixture_text' => "\u{2003}"]);
        $this->assertSame(0, ProductVariantAttributeValue::count());
    }

    public function test_variant_bom_zero_width_and_internal_spaces_are_preserved(): void
    {
        $this->variantDefinition();
        $variant = $this->variant();
        $raw = "\u{3000}\u{FEFF}\u{200B} A  é\u{00A0}B \u{200B}\u{FEFF}\u{3000}";
        $expected = "\u{FEFF}\u{200B} A  é\u{00A0}B \u{200B}\u{FEFF}";
        $this->writeVariant($variant, ['fixture_text' => $raw]);
        $this->assertSame(['fixture_text' => $expected], $this->variantService->activeValues($variant));
    }

    #[DataProvider('invalidConfigurations')]
    public function test_variant_invalid_configuration_is_signaled(string $type, mixed $options): void
    {
        $this->variantDefinition(['input_type' => $type, 'options' => $options]);
        $variant = $this->variant();
        $this->rejected(fn () => $this->variantService->activeValues($variant));
        $this->rejected(fn () => $this->variantService->prepare($variant));
    }

    public function test_variant_required_final_state_and_absent_keys(): void
    {
        $definition = $this->variantDefinition(['is_required' => true]);
        $variant = $this->variant();
        $this->rejected(fn () => $this->writeVariant($variant, []));
        $this->writeVariant($variant, ['fixture_text' => '0']);
        $before = $this->rows();
        $this->writeVariant($variant, []);
        $this->assertSame($before, $this->rows());
        foreach ([null, '', "\u{00A0}"] as $blank) {
            $this->rejected(fn () => $this->writeVariant($variant, ['fixture_text' => $blank]));
        }
        $definition->update(['is_required' => false]);
        $this->writeVariant($variant, ['fixture_text' => null]);
        $definition->update(['is_required' => true]);
        $this->rejected(fn () => $this->writeVariant($variant, []));
    }

    public function test_variant_optional_absence_preserves_row_and_timestamps(): void
    {
        $this->variantDefinition();
        $variant = $this->variant();
        $this->writeVariant($variant, ['fixture_text' => 'old']);
        $before = $this->rows();
        $this->writeVariant($variant, []);
        $this->writeVariant($variant, ['fixture_text' => 'old']);
        $this->assertSame($before, $this->rows());
    }

    public function test_variant_active_invalid_value_requires_correction(): void
    {
        $definition = $this->variantDefinition();
        $variant = $this->variant();
        $this->writeVariant($variant, ['fixture_text' => 'old']);
        $definition->update(['input_type' => 'select', 'options' => ['new']]);
        $this->assertSame([], $this->variantService->activeValues($variant));
        $this->rejected(fn () => $this->writeVariant($variant, []));
        $this->assertSame('old', ProductVariantAttributeValue::value('value'));
        $this->writeVariant($variant, ['fixture_text' => 'new']);
        $this->assertSame(['fixture_text' => 'new'], $this->variantService->activeValues($variant));
    }

    #[DataProvider('historicalCodes')]
    public function test_variant_all_six_historical_codes_are_protected(string $code): void
    {
        $this->variantDefinition(['code' => $code], false);
        $variant = $this->variant();
        $this->rejected(fn () => $this->writeVariant($variant, [$code => null]));
        $this->config->set('attribute_writing.variant_direct', [$code]);
        $this->rejected(fn () => $this->variantService->prepare($variant));
        $this->config->set('attribute_writing.historical.variant', []);
        $this->rejected(fn () => $this->variantService->prepare($variant));
    }

    public static function badAdmissions(): array
    {
        return [[null], ['fixture_text'], [['key' => 'fixture_text']],
            [['fixture_text', 'fixture_text']], [['']], [[0]]];
    }

    #[DataProvider('badAdmissions')]
    public function test_variant_bad_admission_lists_are_refused(mixed $codes): void
    {
        $variant = $this->variant();
        $this->config->set('attribute_writing.variant_direct', $codes);
        $this->rejected(fn () => $this->variantService->prepare($variant));
    }

    public function test_variant_wrong_level_unknown_unadmitted_and_excluded_fields_refused(): void
    {
        $variant = $this->variant();
        $this->variantDefinition([], false);
        foreach (['fixture_text', 'unknown', 'product_id', 'sku', 'barcode', 'prix_achat', 'prix_vente', 'stock'] as $key) {
            $this->rejected(fn () => $this->writeVariant($variant, [$key => null]));
        }
        $this->config->set('attribute_writing.variant_direct', ['fixture_text']);
        $this->variantDefinition(['code' => 'fixture_product', 'level' => 'product']);
        $this->rejected(fn () => $this->variantService->prepare($variant));
    }

    public function test_variant_requires_real_owner_unchanged_identity_and_parent(): void
    {
        $variant = $this->variant();
        $this->rejected(fn () => $this->variantService->prepare(new ProductVariant));
        $unsaved = new ProductVariant(['id' => $variant->id, 'product_id' => $variant->product_id]);
        $this->rejected(fn () => $this->variantService->prepare($unsaved));
        $variant->id = $variant->id + 10000;
        $this->rejected(fn () => $this->variantService->prepare($variant));
        $variant = ProductVariant::first();
        $other = $this->variant();
        $variant->product_id = $other->product_id;
        $this->rejected(fn () => $this->variantService->prepare($variant));
        $variant = ProductVariant::find($variant->id);
        ProductVariant::whereKey($variant->id)->update(['product_id' => $other->product_id]);
        $this->rejected(fn () => $this->variantService->prepare($variant));
        $variant = ProductVariant::find($variant->id);
        $this->connection->table('product_variants')->where('id', $variant->id)->delete();
        $this->rejected(fn () => $this->variantService->prepare($variant));
    }

    public function test_variant_deleted_parent_and_invalid_token_cannot_write(): void
    {
        $this->variantDefinition();
        $variant = $this->variant();
        $this->rejected(fn () => $this->variantService->save($variant, ['fixture_text' => 'value'], 'invalid'));
        $prepared = $this->variantService->prepare($variant);
        // Isolated fixture cascade: a vanished parent must never be silently replaced.
        $this->connection->table('products')->where('id', $variant->product_id)->delete();
        $this->rejected(fn () => $this->variantService->save($variant, ['fixture_text' => 'value'], $prepared));
        $this->rejected(fn () => $this->variantService->activeValues($variant));
    }

    public static function invalidParentContexts(): array
    {
        return [['activity'], ['category_missing'], ['category_incompatible'], ['cycle'], ['ancestor']];
    }

    #[DataProvider('invalidParentContexts')]
    public function test_variant_invalid_persisted_parent_context_refused(string $change): void
    {
        $variant = $this->variant();
        $parent = $variant->product()->first();
        match ($change) {
            'activity' => Product::whereKey($parent->id)->update(['activity' => '']),
            'category_missing' => Product::whereKey($parent->id)->update(['category_id' => null]),
            'category_incompatible' => Product::whereKey($parent->id)->update(['category_id' => $this->makeCategory('other')->id]),
            'cycle' => $this->category->update(['parent_id' => $this->category->id]),
            'ancestor' => $this->category->update(['parent_id' => $this->makeCategory('other')->id]),
        };
        $this->rejected(fn () => $this->variantService->prepare($variant));
        $this->rejected(fn () => $this->variantService->activeValues($variant));
    }

    public function test_variant_context_ignores_cached_parent_and_unsaved_parent_data(): void
    {
        $definition = $this->variantDefinition(['activity' => 'other']);
        $variant = $this->variant();
        $cached = $variant->product;
        $otherCategory = $this->makeCategory('other');
        Product::whereKey($cached->id)->update(['activity' => 'other', 'category_id' => $otherCategory->id]);
        $this->assertSame('fixture_activity', $cached->activity);
        $this->writeVariant($variant, [$definition->code => 'fresh']);
        $this->assertSame(['fixture_text' => 'fresh'], $this->variantService->activeValues($variant));
        $cached->activity = 'invented';
        $cached->category_id = null;
        $before = $this->rows();
        $this->assertSame(['fixture_text' => 'fresh'], $this->variantService->activeValues($variant));
        $this->assertSame($before, $this->rows());
        $this->assertSame($cached, $variant->getRelation('product'));
    }

    public function test_variant_category_inheritance_inactivity_and_revalidated_reactivation(): void
    {
        $definition = $this->variantDefinition(['applicability_mode' => 'category', 'activity' => null,
            'input_type' => 'select', 'options' => ['M', 'L']]);
        $variant = $this->variant();
        $association = CategoryAttributeDefinition::create(['category_id' => $this->category->id,
            'attribute_definition_id' => $definition->id, 'include_descendants' => false]);
        $this->writeVariant($variant, ['fixture_text' => 'M']);
        $row = ProductVariantAttributeValue::first()->getAttributes();
        $child = $this->makeCategory(null, $this->category);
        Product::whereKey($variant->product_id)->update(['category_id' => $child->id]);
        $this->assertSame([], $this->variantService->activeValues($variant));
        $this->writeVariant($variant, []);
        $this->rejected(fn () => $this->writeVariant($variant, ['fixture_text' => null]));
        $this->assertSame($row, ProductVariantAttributeValue::first()->getAttributes());
        $association->update(['include_descendants' => true]);
        $this->assertSame(['fixture_text' => 'M'], $this->variantService->activeValues($variant));
        $this->assertSame($row, ProductVariantAttributeValue::first()->getAttributes());
        $association->delete();
        $definition->update(['options' => ['L']]);
        CategoryAttributeDefinition::create(['category_id' => $child->id, 'attribute_definition_id' => $definition->id]);
        $this->assertSame([], $this->variantService->activeValues($variant));
        $this->rejected(fn () => $this->writeVariant($variant, []));
        $this->assertSame($row, ProductVariantAttributeValue::first()->getAttributes());
        $this->writeVariant($variant, ['fixture_text' => 'L']);
        $this->assertSame(['fixture_text' => 'L'], $this->variantService->activeValues($variant));
    }

    public function test_variant_activity_scope_and_activity_mode_ignore_associations(): void
    {
        $definition = $this->variantDefinition();
        $variant = $this->variant();
        $association = CategoryAttributeDefinition::create(['category_id' => $this->category->id,
            'attribute_definition_id' => $definition->id]);
        $this->writeVariant($variant, ['fixture_text' => 'value']);
        $association->delete();
        $this->assertSame(['fixture_text' => 'value'], $this->variantService->activeValues($variant));
        $definition->update(['activity' => 'other']);
        $this->assertSame([], $this->variantService->activeValues($variant));
        $this->rejected(fn () => $this->writeVariant($variant, ['fixture_text' => null]));
        $this->assertSame('value', ProductVariantAttributeValue::value('value'));
    }

    public static function variantChanges(): array
    {
        return array_map(fn ($change) => [$change], ['options', 'type', 'required', 'scope', 'mode', 'registry',
            'category', 'ancestor', 'association', 'variant', 'parent', 'activity', 'values', 'reparent', 'delete']);
    }

    #[DataProvider('variantChanges')]
    public function test_variant_obsolescence_refuses_before_any_write(string $change): void
    {
        $definition = $this->variantDefinition();
        $variant = $this->variant();
        $this->writeVariant($variant, ['fixture_text' => 'old']);
        $prepared = $this->variantService->prepare($variant);
        match ($change) {
            'options' => $definition->update(['input_type' => 'select', 'options' => ['old']]),
            'type' => $definition->update(['input_type' => 'select', 'options' => ['old', 'new']]),
            'required' => $definition->update(['is_required' => true]),
            'scope' => $definition->update(['activity' => null]),
            'mode' => $definition->update(['applicability_mode' => 'category']),
            'registry' => $this->config->set('attribute_writing.variant_direct', []),
            'category' => Product::whereKey($variant->product_id)->update(['category_id' => $this->makeCategory()->id]),
            'ancestor' => $this->category->update(['parent_id' => $this->makeCategory()->id]),
            'association' => CategoryAttributeDefinition::create(['category_id' => $this->category->id,
                'attribute_definition_id' => $definition->id]),
            'variant' => ProductVariant::whereKey($variant->id)->update(['sku' => 'concurrent-fixture']),
            'parent' => Product::whereKey($variant->product_id)->update(['nom' => 'concurrent']),
            'activity' => Product::whereKey($variant->product_id)->update(['activity' => 'other']),
            'values' => ProductVariantAttributeValue::where('product_variant_id', $variant->id)->update(['value' => 'concurrent']),
            'reparent' => ProductVariant::whereKey($variant->id)->update(['product_id' => $this->variant()->product_id]),
            'delete' => $this->connection->table('product_variants')->where('id', $variant->id)->delete(),
        };
        $this->connection->enableQueryLog();
        $this->connection->flushQueryLog();
        $this->rejected(fn () => $this->variantService->save($variant, ['fixture_text' => 'new'], $prepared));
        foreach ($this->connection->getQueryLog() as $query) {
            $this->assertSame(0, preg_match('/\A\s*(insert|update|delete)\b/i', $query['query']));
        }
        $this->connection->disableQueryLog();
    }

    public static function firstWrites(): array
    {
        return [['insert'], ['update'], ['delete']];
    }

    #[DataProvider('firstWrites')]
    public function test_variant_late_failure_rolls_back_all_direct_values(string $firstWrite): void
    {
        $first = $this->variantDefinition();
        // Resolver orders by code: ensure the first write precedes the injected failure.
        $second = $this->variantDefinition(['code' => 'fixture_z_second']);
        $variant = $this->variant();
        if ($firstWrite !== 'insert') {
            $this->writeVariant($variant, ['fixture_text' => 'old', 'fixture_z_second' => 'old']);
        }
        $before = $this->rows();
        $prepared = $this->variantService->prepare($variant);
        $firstCompleted = false;
        ProductVariantAttributeValue::saved(function (ProductVariantAttributeValue $row) use ($first, &$firstCompleted): void {
            if ((int) $row->attribute_definition_id === (int) $first->id) {
                $firstCompleted = true;
            }
        });
        ProductVariantAttributeValue::saving(function (ProductVariantAttributeValue $row) use ($first, $second, $firstWrite, &$firstCompleted): void {
            if ((int) $row->attribute_definition_id === (int) $second->id) {
                if ($firstWrite === 'delete') {
                    $firstCompleted = ! ProductVariantAttributeValue::where('attribute_definition_id', $first->id)->exists();
                }
                throw new RuntimeException('Fixture late Variant value failure');
            }
        });
        try {
            $this->variantService->save($variant,
                ['fixture_text' => $firstWrite === 'delete' ? null : 'first', 'fixture_z_second' => 'second'], $prepared);
            $this->fail('Echec tardif attendu.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture late Variant value failure', $exception->getMessage());
        }
        $this->assertTrue($firstCompleted);
        $this->assertSame($before, $this->rows());
        $this->assertSame(1, ProductVariant::count());
    }

    public function test_variant_writes_do_not_save_owners_touch_mirrors_or_dirty_fields(): void
    {
        $this->variantDefinition();
        foreach (['season', 'taille', 'equipe'] as $code) {
            $this->definition(['code' => $code], false);
        }
        foreach (['size', 'color', 'version'] as $code) {
            $this->variantDefinition(['code' => $code], false);
        }
        $variant = $this->variant();
        $other = $this->variant();
        $before = $this->rows();
        $attributes = $variant->getAttributes();
        $variant->sku = 'unsaved';
        $variant->barcode = 'unsaved';
        $variant->prix_achat = '99.00';
        $variant->prix_vente = '199.00';
        $variant->stock = 999;
        Product::saving(fn () => throw new RuntimeException('Owner hook must not run'));
        ProductVariant::saving(fn () => throw new RuntimeException('Owner hook must not run'));
        ProductVariant::deleting(fn () => throw new RuntimeException('Owner hook must not run'));
        $this->connection->enableQueryLog();
        $this->connection->flushQueryLog();
        $this->writeVariant($variant, ['fixture_text' => '0']);
        $after = $this->rows();
        foreach ($before as $table => $rows) {
            if ($table !== 'product_variant_attribute_values') {
                $this->assertSame($rows, $after[$table]);
            }
        }
        foreach (['size', 'color', 'version'] as $code) {
            $this->assertSame($attributes[$code], $variant->attributeMirrorValue($code));
        }
        $directId = AttributeDefinition::where('code', 'fixture_text')->value('id');
        $historicalAfter = array_values(array_filter($after['product_variant_attribute_values'],
            fn ($row) => (int) $row['attribute_definition_id'] !== (int) $directId));
        $this->assertSame($before['product_variant_attribute_values'], $historicalAfter);
        $this->assertSame([], $this->variantService->activeValues($other));
        $this->assertSame('unsaved', $variant->sku);
        $this->assertSame(999, $variant->stock);
        $writes = [];
        foreach ($this->connection->getQueryLog() as $query) {
            if (preg_match('/\A\s*(insert|update|delete)\b/i', $query['query'])) {
                $writes[] = $query['query'];
                $this->assertMatchesRegularExpression('/\A\s*(insert into|update|delete from)\s+"?product_variant_attribute_values\b/i', $query['query']);
            }
        }
        $this->assertNotEmpty($writes);
        $this->connection->disableQueryLog();
    }

    public function test_variant_reads_preserve_raw_text_and_are_read_only(): void
    {
        $this->variantDefinition();
        $variant = $this->variant();
        $this->writeVariant($variant, ['fixture_text' => 'normal']);
        ProductVariantAttributeValue::where('product_variant_id', $variant->id)->update(['value' => ' old ']);
        $before = $this->rows();
        $this->connection->enableQueryLog();
        $this->connection->flushQueryLog();
        $this->variantService->prepare($variant);
        $this->assertSame(['fixture_text' => ' old '], $this->variantService->activeValues($variant));
        foreach ($this->connection->getQueryLog() as $query) {
            $this->assertSame(0, preg_match('/\A\s*(insert|update|delete|create|alter|drop)\b/i', $query['query']));
        }
        $this->connection->disableQueryLog();
        $this->writeVariant($variant, []);
        $this->assertSame($before, $this->rows());
    }

    public function test_product_fingerprint_and_behavior_independent_of_variant_configuration(): void
    {
        $this->definition();
        $product = $this->product();
        $this->service->save($product, ['fixture_text' => 'old'], $this->service->prepare($product));
        $prepared = $this->service->prepare($product);
        $snapshot = (new AttributeDirectWriteRegistry($this->config))->snapshot();
        foreach ([['fixture_variant'], ['size'], 'invalid', null] as $codes) {
            $prepared = $this->service->prepare($product);
            $this->config->set('attribute_writing.variant_direct', $codes);
            $this->assertSame($snapshot, (new AttributeDirectWriteRegistry($this->config))->snapshot());
            $this->assertSame($prepared, $this->service->prepare($product));
            $this->service->save($product, [], $prepared);
            $this->assertSame(['fixture_text' => 'old'], $this->service->activeValues($product));
        }
        $this->assertSame('old', ProductAttributeValue::value('value'));
    }

    public function test_variant_fingerprint_independent_of_product_admissions(): void
    {
        $this->variantDefinition();
        $variant = $this->variant();
        $prepared = $this->variantService->prepare($variant);
        $this->config->set('attribute_writing.direct', ['invalid_product_code']);
        $this->assertSame($prepared, $this->variantService->prepare($variant));
        $this->variantService->save($variant, ['fixture_text' => 'value'], $prepared);
        $this->assertSame(['fixture_text' => 'value'], $this->variantService->activeValues($variant));
    }

    public function test_variant_incompatible_connection_is_refused_without_cross_db_writes(): void
    {
        $variant = $this->variant();
        $other = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
        $resolver = new ConnectionResolver(['direct_write' => $this->connection, 'other' => $other]);
        $resolver->setDefaultConnection('direct_write');
        Model::setConnectionResolver($resolver);
        $variant->setConnection('other');
        $this->rejected(fn () => $this->variantService->prepare($variant));
        $this->rejected(fn () => $this->variantService->activeValues($variant));
        $this->rejected(fn () => $this->variantService->save($variant, [], 'invalid'));
    }
}
