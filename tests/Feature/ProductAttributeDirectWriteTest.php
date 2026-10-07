<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\CategoryAttributeDefinition;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\AttributeApplicabilityResolver;
use App\Services\AttributeDirectWriteRegistry;
use App\Services\ProductAttributeWriteService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/** No application/.env bootstrap, seeders, real catalogue or global migrations. */
class ProductAttributeDirectWriteTest extends TestCase
{
    protected Connection $connection;
    protected Repository $config;
    protected ProductAttributeWriteService $service;
    protected Category $category;
    private mixed $previousApplication;
    private mixed $previousResolver;
    private mixed $previousDispatcher;
    private string|false $previousIgnoreArgs;

    protected function openConnection(): Connection
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
        $connection->statement('PRAGMA foreign_keys = ON');

        return $connection;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '1');
        if (ini_get('zend.exception_ignore_args') !== '1') {
            throw new RuntimeException('Masquage des arguments requis.');
        }
        $this->connection = $this->openConnection();
        $resolver = new ConnectionResolver(['direct_write' => $this->connection]);
        $resolver->setDefaultConnection('direct_write');
        $container = new Container;
        $container->instance('db', $resolver);
        $container->instance('db.schema', $this->connection->getSchemaBuilder());
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'fr'), $container));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Model::setConnectionResolver($resolver);
        Model::setEventDispatcher(new Dispatcher($container));
        Model::clearBootedModels();
        $this->connection->beginTransaction();
        foreach (['2026_07_27_212054_create_categories_table.php', '2026_09_02_100001_add_activity_to_categories_table.php',
            '2026_09_02_100002_create_attribute_definitions_table.php'] as $file) {
            $this->migration($file)->up();
        }
        // Supports for the unchanged Product hooks, not an application schema migration.
        foreach (['brands', 'suppliers', 'clubs'] as $table) {
            $this->connection->getSchemaBuilder()->create($table, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }
        $this->connection->getSchemaBuilder()->create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('activity');
            $table->foreignId('category_id')->nullable()->constrained('categories');
            foreach (['brand_id', 'supplier_id', 'club_id', 'competition_id'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            foreach (['nom', 'reference', 'taille', 'season', 'equipe', 'categorie', 'marque', 'fournisseur'] as $column) {
                $table->string($column)->nullable();
            }
            $table->integer('stock')->default(0);
            $table->timestamps();
        });
        $this->migration('2026_09_02_100003_create_product_attribute_values_table.php')->up();
        $this->migration('2026_10_06_100000_add_category_applicability_to_attribute_definitions.php')->up();
        $this->config = new Repository(['attribute_writing' => require dirname(__DIR__, 2).'/config/attribute_writing.php']);
        $this->service = new ProductAttributeWriteService(new AttributeApplicabilityResolver, new AttributeDirectWriteRegistry($this->config));
        $this->category = $this->makeCategory();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->connection)) {
                $this->connection->rollBack(0);
            }
        } catch (Throwable) {
            throw new RuntimeException('Nettoyage des fixtures impossible : détails masqués.');
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousApplication);
            if ($this->previousResolver === null) {
                Model::unsetConnectionResolver();
            } else {
                Model::setConnectionResolver($this->previousResolver);
            }
            if ($this->previousDispatcher === null) {
                Model::unsetEventDispatcher();
            } else {
                Model::setEventDispatcher($this->previousDispatcher);
            }
            Model::clearBootedModels();
            if ($this->previousIgnoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs);
            }
            parent::tearDown();
        }
    }

    private function migration(string $file): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/'.$file;
    }

    protected function makeCategory(?string $activity = 'fixture_activity', ?Category $parent = null): Category
    {
        return Category::create(['name' => 'Fixture', 'slug' => 'fixture-'.bin2hex(random_bytes(6)),
            'activity' => $activity, 'parent_id' => $parent?->id]);
    }

    protected function definition(array $overrides = [], bool $admit = true): AttributeDefinition
    {
        $definition = AttributeDefinition::create(array_replace([
            'code' => 'fixture_text', 'label' => 'Fixture', 'activity' => 'fixture_activity',
            'level' => 'product', 'input_type' => 'text', 'options' => null,
            'is_required' => false, 'applicability_mode' => 'activity',
        ], $overrides));
        if ($admit) {
            $codes = $this->config->get('attribute_writing.direct');
            $codes[] = $definition->code;
            $this->config->set('attribute_writing.direct', $codes);
        }

        return $definition;
    }

    protected function product(): Product
    {
        return new Product(['activity' => 'fixture_activity', 'category_id' => $this->category->id,
            'nom' => 'Fixture', 'reference' => 'Fixture', 'taille' => 'M', 'equipe' => 'Fixture', 'season' => 'Fixture']);
    }

    protected function save(Product $product, array $values): void
    {
        $this->service->save($product, $values, $this->service->prepare($product));
    }

    protected function rows(): array
    {
        $rows = [];
        foreach (['products', 'product_attribute_values', 'attribute_definitions', 'category_attribute_definition'] as $table) {
            $rows[$table] = $this->connection->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    protected function rejected(callable $action): void
    {
        $before = $this->rows();
        try {
            $action();
            $this->fail('ValidationException attendue.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame($before, $this->rows());
    }

    public function test_registry_defaults_empty_and_admission_is_explicit(): void
    {
        $this->assertSame([], $this->config->get('attribute_writing.direct'));
        $this->definition([], false);
        $product = $this->product();
        $this->rejected(fn () => $this->save($product, ['fixture_text' => 'value']));
        $this->assertSame([], $this->service->activeValues($product));
        $this->config->set('attribute_writing.direct', ['fixture_text']);
        $this->save($product, ['fixture_text' => 'value']);
        $this->assertSame(['fixture_text' => 'value'], $this->service->activeValues($product));
    }

    public static function values(): array
    {
        return [
            'Unicode perimeter' => ["\u{00A0}\u{2003} A  é\u{00A0}B \u{3000}", "A  é\u{00A0}B"],
            'zero' => ['0', '0'], 'null' => [null, null], 'empty' => ['', null],
            'spaces' => [" \t\u{00A0}\u{202F}", null], '255 Unicode' => [str_repeat('é', 255), str_repeat('é', 255)],
            'zero width preserved' => ["\u{200B}A\u{200B}", "\u{200B}A\u{200B}"],
        ];
    }

    #[DataProvider('values')]
    public function test_text_normalization_and_empty_semantics(mixed $value, ?string $expected): void
    {
        $this->definition();
        $product = $this->product();
        $this->save($product, ['fixture_text' => $value]);
        $this->assertSame($expected === null ? [] : ['fixture_text' => $expected], $this->service->activeValues($product));
        $this->assertSame($expected === null ? 0 : 1, ProductAttributeValue::count());
        $this->save($product, ['fixture_text' => 'existing']);
        $this->save($product, ['fixture_text' => $value]);
        $this->assertSame($expected, ProductAttributeValue::value('value'));
    }

    public static function invalidValues(): array
    {
        return [['text', 0], ['text', false], ['text', []], ['text', new \stdClass],
            ['text', "\xFF"], ['text', str_repeat('é', 256)],
            ['select', 'm'], ['select', ' M '], ['select', 'E'], ['select', 0], ['select', str_repeat('é', 256)]];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_refused_without_truncation(string $type, mixed $value): void
    {
        $this->definition(['input_type' => $type, 'options' => $type === 'select' ? ['M', 'É', '0'] : null]);
        $this->rejected(fn () => $this->save($this->product(), ['fixture_text' => $value]));
    }

    public function test_select_exact_zero_and_unicode_limit(): void
    {
        $long = str_repeat('é', 255);
        $this->definition(['input_type' => 'select', 'options' => ['É', '0', $long]]);
        $product = $this->product();
        foreach (['É', '0', $long] as $value) {
            $this->save($product, ['fixture_text' => $value]);
            $this->assertSame(['fixture_text' => $value], $this->service->activeValues($product));
        }
        $this->save($product, ['fixture_text' => "\u{2003}"]);
        $this->assertSame(0, ProductAttributeValue::count());
    }

    public static function invalidConfigurations(): array
    {
        return [['number', null], ['text', ['x']], ['select', null], ['select', []],
            ['select', ['M', 'M']], ['select', ['']], ['select', [' ']], ['select', [0]],
            ['select', ['key' => 'M']], ['select', [str_repeat('é', 256)]]];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_bad_configuration_is_not_silently_hidden(string $type, mixed $options): void
    {
        $this->definition(['input_type' => $type, 'options' => $options]);
        $this->rejected(fn () => $this->service->activeValues($this->product()));
    }

    public function test_required_final_state_absent_key_and_zero(): void
    {
        $this->definition(['is_required' => true]);
        $product = $this->product();
        $this->rejected(fn () => $this->save($product, []));
        $this->save($product, ['fixture_text' => '0']);
        $row = ProductAttributeValue::first()->getAttributes();
        $this->save($product, []);
        $this->assertSame($row, ProductAttributeValue::first()->getAttributes());
        foreach ([null, '', "\u{00A0}"] as $blank) {
            $this->rejected(fn () => $this->save($product, ['fixture_text' => $blank]));
        }
    }

    public function test_absent_optional_key_keeps_exact_existing_row(): void
    {
        $this->definition();
        $product = $this->product();
        $this->save($product, ['fixture_text' => 'old']);
        $row = ProductAttributeValue::first()->getAttributes();
        $this->save($product, []);
        $this->assertSame($row, ProductAttributeValue::first()->getAttributes());
    }

    public function test_applicable_old_value_invalid_under_current_rules_requires_correction(): void
    {
        $definition = $this->definition();
        $product = $this->product();
        $this->save($product, ['fixture_text' => 'old']);
        $definition->update(['input_type' => 'select', 'options' => ['new']]);
        $this->assertSame([], $this->service->activeValues($product));
        $this->rejected(fn () => $this->save($product, []));
        $this->assertSame('old', ProductAttributeValue::value('value'));
        $this->save($product, ['fixture_text' => 'new']);
        $this->assertSame(['fixture_text' => 'new'], $this->service->activeValues($product));
        $definition->update(['options' => ['0']]);
        $this->assertSame([], $this->service->activeValues($product));
        $this->rejected(fn () => $this->save($product, []));
        $this->save($product, ['fixture_text' => '0']);
        $this->assertSame('0', ProductAttributeValue::value('value'));
    }

    public function test_newly_required_missing_value_blocks_even_with_absent_key(): void
    {
        $definition = $this->definition();
        $product = $this->product();
        $this->save($product, []);
        $definition->update(['is_required' => true]);
        $this->rejected(fn () => $this->save($product, []));
        $this->save($product, ['fixture_text' => '0']);
        $this->assertSame(['fixture_text' => '0'], $this->service->activeValues($product));
    }

    public function test_reads_and_preparation_are_read_only_and_preserve_raw_stored_text(): void
    {
        $this->definition();
        $product = $this->product();
        $this->save($product, ['fixture_text' => 'normal']);
        ProductAttributeValue::where('product_id', $product->id)->update(['value' => ' old ']);
        $before = $this->rows();
        $this->connection->enableQueryLog();
        $this->connection->flushQueryLog();
        $this->service->prepare($product);
        $this->assertSame(['fixture_text' => ' old '], $this->service->activeValues($product));
        $queries = $this->connection->getQueryLog();
        $this->connection->disableQueryLog();
        foreach ($queries as $query) {
            $this->assertSame(0, preg_match('/\A\s*(insert|update|delete|create|alter|drop)\b/i', $query['query']));
        }
        $this->assertSame($before, $this->rows());
        $this->save($product, []);
        $this->assertSame(' old ', ProductAttributeValue::value('value'));
    }

    public function test_registry_cannot_disable_historical_protection_or_duplicate_admissions(): void
    {
        $this->definition();
        $this->config->set('attribute_writing.direct', ['fixture_text', 'fixture_text']);
        $this->rejected(fn () => $this->service->prepare($this->product()));
        $this->config->set('attribute_writing.direct', ['season']);
        $this->config->set('attribute_writing.historical.product', []);
        $this->rejected(fn () => $this->service->prepare($this->product()));
    }

    public function test_context_missing_incompatible_and_activity_mutation_refused(): void
    {
        $this->definition();
        foreach ([null, 999999, $this->makeCategory('other')->id] as $categoryId) {
            $product = $this->product();
            $product->category_id = $categoryId;
            $this->rejected(fn () => $this->save($product, []));
        }
        $product = $this->product();
        $product->activity = '';
        $this->rejected(fn () => $this->save($product, []));
        $product = $this->product();
        $this->save($product, []);
        $product->activity = 'other';
        $this->rejected(fn () => $this->save($product, []));
    }

    public function test_cycle_is_refused_even_without_categorical_definition(): void
    {
        $this->category->update(['parent_id' => $this->category->id]);
        $this->rejected(fn () => $this->service->prepare($this->product()));
    }

    public static function historicalCodes(): array
    {
        return array_map(fn ($code) => [$code], ['season', 'taille', 'equipe', 'size', 'color', 'version']);
    }

    #[DataProvider('historicalCodes')]
    public function test_historical_codes_refused_even_when_explicitly_admitted(string $code): void
    {
        $this->definition(['code' => $code], false);
        $product = $this->product();
        $this->rejected(fn () => $this->save($product, [$code => null]));
        $this->config->set('attribute_writing.direct', [$code]);
        $this->rejected(fn () => $this->service->prepare($product));
    }

    public function test_unknown_and_wrong_level_refused(): void
    {
        $this->definition();
        $this->rejected(fn () => $this->save($this->product(), ['unknown' => null]));
        $this->definition(['code' => 'fixture_variant', 'level' => 'variant']);
        $this->rejected(fn () => $this->service->prepare($this->product()));
    }

    public function test_categorical_inheritance_non_applicability_and_reactivation(): void
    {
        $definition = $this->definition(['applicability_mode' => 'category', 'activity' => null,
            'input_type' => 'select', 'options' => ['M', 'L']]);
        $child = $this->makeCategory(null, $this->category);
        $association = CategoryAttributeDefinition::create(['category_id' => $this->category->id,
            'attribute_definition_id' => $definition->id, 'include_descendants' => false]);
        $product = $this->product();
        $this->save($product, ['fixture_text' => 'M']);
        $product->category_id = $child->id;
        $this->save($product, []);
        $row = ProductAttributeValue::first()->getAttributes();
        $this->assertSame([], $this->service->activeValues($product));
        $this->rejected(fn () => $this->save($product, ['fixture_text' => 'L']));
        $association->update(['include_descendants' => true]);
        $this->assertSame(['fixture_text' => 'M'], $this->service->activeValues($product));
        $this->assertSame($row, ProductAttributeValue::first()->getAttributes());
        $association->delete();
        $this->assertSame([], $this->service->activeValues($product));
        $definition->update(['options' => ['L']]);
        CategoryAttributeDefinition::create(['category_id' => $child->id, 'attribute_definition_id' => $definition->id]);
        $this->assertSame([], $this->service->activeValues($product));
        $this->rejected(fn () => $this->save($product, []));
        $this->save($product, ['fixture_text' => 'L']);
        $this->assertSame(['fixture_text' => 'L'], $this->service->activeValues($product));
    }

    public function test_activity_mode_ignores_associations_and_scope_still_applies(): void
    {
        $definition = $this->definition();
        CategoryAttributeDefinition::create(['category_id' => $this->category->id, 'attribute_definition_id' => $definition->id]);
        $product = $this->product();
        $this->save($product, ['fixture_text' => 'value']);
        $definition->categoryAssociations()->delete();
        $this->assertSame(['fixture_text' => 'value'], $this->service->activeValues($product));
        $definition->update(['activity' => 'other']);
        $this->assertSame([], $this->service->activeValues($product));
        $this->rejected(fn () => $this->save($product, ['fixture_text' => null]));
        $this->assertSame('value', ProductAttributeValue::value('value'));
    }

    public static function changes(): array
    {
        return array_map(fn ($change) => [$change], ['options', 'type', 'required', 'scope', 'mode', 'registry', 'category', 'parent', 'association', 'owner', 'values']);
    }

    #[DataProvider('changes')]
    public function test_preparation_obsolescence_requires_refresh(string $change): void
    {
        $definition = $this->definition();
        $product = $this->product();
        $this->save($product, ['fixture_text' => 'old']);
        $prepared = $this->service->prepare($product);
        match ($change) {
            'options' => $definition->update(['input_type' => 'select', 'options' => ['old']]),
            'type' => $definition->update(['input_type' => 'select', 'options' => ['old', 'new']]),
            'required' => $definition->update(['is_required' => true]),
            'scope' => $definition->update(['activity' => null]),
            'mode' => $definition->update(['applicability_mode' => 'category']),
            'registry' => $this->config->set('attribute_writing.direct', []),
            'category' => $product->setAttribute('category_id', $this->makeCategory()->id),
            'parent' => $this->category->update(['parent_id' => $this->makeCategory()->id]),
            'association' => CategoryAttributeDefinition::create(['category_id' => $this->category->id, 'attribute_definition_id' => $definition->id]),
            'owner' => Product::whereKey($product->id)->update(['nom' => 'concurrent']),
            'values' => ProductAttributeValue::where('product_id', $product->id)->update(['value' => 'concurrent']),
        };
        $this->rejected(fn () => $this->service->save($product, [], $prepared));
    }

    public static function failureStages(): array
    {
        return [['create', 'value'], ['edit', 'value'], ['create', 'product'], ['edit', 'product']];
    }

    #[DataProvider('failureStages')]
    public function test_late_failure_rolls_back_owner_values_and_real_mirrors(string $operation, string $stage): void
    {
        $first = $this->definition();
        $second = $this->definition(['code' => 'fixture_second']);
        // Isolated definitions matching mirror identities, never admitted directly.
        foreach (['season', 'taille', 'equipe'] as $code) {
            $this->definition(['code' => $code], false);
        }
        $product = $this->product();
        if ($operation === 'edit') {
            $this->save($product, ['fixture_text' => 'old', 'fixture_second' => 'old']);
        }
        $before = $this->rows();
        $product->nom = 'changed';
        $product->season = 'changed';
        $prepared = $this->service->prepare($product);
        if ($stage === 'value') {
            ProductAttributeValue::saving(function (ProductAttributeValue $value) use ($second): void {
                if ((int) $value->attribute_definition_id === (int) $second->id) {
                    throw new RuntimeException('Fixture late value failure');
                }
            });
        } else {
            Product::saved(fn () => throw new RuntimeException('Fixture late product failure'));
        }
        try {
            $this->service->save($product, ['fixture_text' => $operation === 'edit' ? null : 'first', 'fixture_second' => 'second'], $prepared);
            $this->fail('Echec tardif attendu.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Fixture late', $exception->getMessage());
        }
        $this->assertSame($before, $this->rows());
        $this->assertSame($operation === 'edit', $product->exists);
        if ($operation === 'create') {
            $this->assertNull($product->id);
        }
        $this->assertNotNull($first->id);
    }

    public function test_success_preserves_mirror_ownership_and_definitions(): void
    {
        $this->definition();
        foreach (['season', 'taille', 'equipe'] as $code) {
            $this->definition(['code' => $code], false);
        }
        $definitions = $this->rows()['attribute_definitions'];
        $product = $this->product();
        $this->connection->enableQueryLog();
        $this->connection->flushQueryLog();
        $this->save($product, ['fixture_text' => '0']);
        foreach (['season', 'taille', 'equipe'] as $code) {
            $this->assertSame($product->{$code}, $product->attributeMirrorValue($code));
        }
        $product->season = 'next';
        $this->save($product, []);
        $this->assertSame('next', $product->attributeMirrorValue('season'));
        $this->assertSame(['fixture_text' => '0'], $this->service->activeValues($product));
        $this->assertSame($definitions, $this->rows()['attribute_definitions']);
        foreach ($this->connection->getQueryLog() as $query) {
            $this->assertSame(0, preg_match('/\b(insert into|update|delete from)\s+["`]?product_variant/i', $query['query']));
        }
        $this->connection->disableQueryLog();
    }
}
