<?php

namespace Tests\Feature;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\CategoryAttributeDefinition;
use App\Services\AttributeApplicabilityResolver;
use Database\Seeders\AttributeDefinitionSeeder;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
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

/** Fixtures isolées, sans bootstrap Laravel/.env et sans down destructif. */
class AttributeApplicabilityFoundationTest extends TestCase
{
    protected Connection $connection;

    private mixed $previousApplication;

    private mixed $previousResolver;

    private mixed $previousDispatcher;

    private string|false $previousIgnoreArgs;

    protected array $historicalDefinitions;

    private array $historicalValues;

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
        $resolver = new ConnectionResolver(['applicability' => $this->connection]);
        $resolver->setDefaultConnection('applicability');
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
        $this->migration('2026_07_27_212054_create_categories_table.php')->up();
        $this->migration('2026_09_02_100001_add_activity_to_categories_table.php')->up();
        $this->migration('2026_09_02_100002_create_attribute_definitions_table.php')->up();
        // Supports minimaux de FK : aucune donnée métier ni migration globale.
        $this->connection->getSchemaBuilder()->create('products', fn (Blueprint $table) => $table->id());
        $this->connection->getSchemaBuilder()->create('product_variants', fn (Blueprint $table) => $table->id());
        $this->migration('2026_09_02_100003_create_product_attribute_values_table.php')->up();
        $this->migration('2026_09_02_100004_create_product_variant_attribute_values_table.php')->up();
        (new AttributeDefinitionSeeder)->run();
        $this->historicalDefinitions = $this->definitionRows();
        $this->connection->table('products')->insert(['id' => 1]);
        $this->connection->table('product_variants')->insert(['id' => 1]);
        $this->connection->table('product_attribute_values')->insert([
            'product_id' => 1, 'attribute_definition_id' => AttributeDefinition::where('code', 'season')->value('id'),
            'value' => ' 0 ', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->connection->table('product_variant_attribute_values')->insert([
            'product_variant_id' => 1, 'attribute_definition_id' => AttributeDefinition::where('code', 'color')->value('id'),
            'value' => 'Bleu', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->historicalValues = $this->valueRows();
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->connection) && $this->connection->transactionLevel() > 0) {
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

    protected function migration(string $file = '2026_10_06_100000_add_category_applicability_to_attribute_definitions.php'): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/'.$file;
    }

    private function definitionRows(): array
    {
        return $this->connection->table('attribute_definitions')->orderBy('id')->get()
            ->map(fn ($row): array => (array) $row)->all();
    }

    private function valueRows(): array
    {
        return array_map(fn (string $table): array => $this->connection->table($table)->orderBy('id')->get()
            ->map(fn ($row): array => (array) $row)->all(), ['product_attribute_values', 'product_variant_attribute_values']);
    }

    protected function category(?string $activity = 'alpha', ?Category $parent = null): Category
    {
        static $sequence = 0;

        return Category::create(['name' => 'Fixture', 'slug' => 'fixture-'.++$sequence,
            'activity' => $activity, 'parent_id' => $parent?->id]);
    }

    protected function definition(string $code = 'fixture', string $mode = 'category', ?string $activity = 'alpha', string $level = 'product'): AttributeDefinition
    {
        return AttributeDefinition::create(['code' => $code, 'label' => 'Fixture', 'activity' => $activity,
            'level' => $level, 'applicability_mode' => $mode]);
    }

    protected function associate(Category $category, AttributeDefinition $definition, bool $inherit = false): CategoryAttributeDefinition
    {
        return CategoryAttributeDefinition::create(['category_id' => $category->id,
            'attribute_definition_id' => $definition->id, 'include_descendants' => $inherit]);
    }

    protected function codes(?int $categoryId, string $activity = 'alpha', string $level = 'product'): array
    {
        return (new AttributeApplicabilityResolver)->resolve($activity, $categoryId, $level)->pluck('code')->all();
    }

    protected function refuse(callable $operation, string $type = RuntimeException::class): void
    {
        $error = null;
        try {
            // Savepoint : une contrainte refusée ne doit pas casser la transaction PostgreSQL englobante.
            $this->connection->transaction($operation);
        } catch (Throwable $exception) {
            $error = $exception;
        }
        $this->assertInstanceOf($type, $error);
    }

    public function test_migration_preserves_all_thirteen_preexisting_definitions_and_value_tables(): void
    {
        $after = $this->definitionRows();
        $this->assertCount(13, $after);
        foreach ($after as &$row) {
            $this->assertSame('activity', $row['applicability_mode']);
            unset($row['applicability_mode']);
        }
        unset($row);
        $this->assertSame($this->historicalDefinitions, $after);
        $this->assertSame(0, $this->connection->table('category_attribute_definition')->count());
        $this->assertSame($this->historicalValues, $this->valueRows());
        (new AttributeDefinitionSeeder)->run();
        $this->assertSame(array_fill(0, 13, 'activity'), AttributeDefinition::orderBy('id')->pluck('applicability_mode')->all());
    }

    public function test_database_mode_default_check_and_not_null(): void
    {
        $definition = AttributeDefinition::create(['code' => 'default', 'label' => 'Fixture', 'level' => 'product']);
        $this->assertSame('activity', $definition->fresh()->applicability_mode);
        foreach (['unknown', null] as $invalid) {
            $this->refuse(fn () => $this->connection->table('attribute_definitions')
                ->where('id', $definition->id)->update(['applicability_mode' => $invalid]), QueryException::class);
        }
    }

    public function test_association_database_defaults_foreign_keys_unique_and_restrict(): void
    {
        $category = $this->category();
        $definition = $this->definition();
        $id = $this->connection->table('category_attribute_definition')->insertGetId([
            'category_id' => $category->id, 'attribute_definition_id' => $definition->id,
        ]);
        $this->assertFalse(CategoryAttributeDefinition::findOrFail($id)->include_descendants);
        $this->refuse(fn () => $this->connection->table('category_attribute_definition')->insert([
            'category_id' => $category->id, 'attribute_definition_id' => $definition->id,
        ]), QueryException::class);
        foreach ([['category_id' => 999999, 'attribute_definition_id' => $definition->id],
            ['category_id' => $category->id, 'attribute_definition_id' => 999999]] as $invalid) {
            $this->refuse(fn () => $this->connection->table('category_attribute_definition')->insert($invalid), QueryException::class);
        }
        $this->refuse(fn () => $category->delete(), QueryException::class);
        $this->refuse(fn () => $definition->delete(), QueryException::class);
        $this->assertTrue($this->connection->getSchemaBuilder()->hasIndex(
            'category_attribute_definition', 'category_attribute_definition_definition_index'));
    }

    public function test_associations_validate_fresh_owners_on_creation_and_modification(): void
    {
        $category = $this->category();
        $definition = $this->definition();
        $association = $this->associate($category, $definition);
        $this->assertSame($association->id, $category->attributeAssociations()->sole()->id);
        $this->assertSame($association->id, $definition->categoryAssociations()->sole()->id);
        $association->load('category', 'attributeDefinition');
        $other = $this->category('beta');
        $this->refuse(fn () => $association->update(['category_id' => $other->id]), ValidationException::class);
        $this->assertSame($category->id, $association->fresh()->category_id);
        $this->refuse(fn () => $this->associate($other, $definition), ValidationException::class);
        $this->refuse(fn () => CategoryAttributeDefinition::create(['category_id' => 999999,
            'attribute_definition_id' => $definition->id]), ValidationException::class);
        $this->refuse(fn () => CategoryAttributeDefinition::create(['category_id' => $category->id,
            'attribute_definition_id' => 999999]), ValidationException::class);
        $this->assertNotNull($this->associate($other, $this->definition('transverse', activity: null))->id);
        $this->assertNotNull($this->associate($this->category(null), $definition)->id);
        Category::whereKey($category->id)->update(['activity' => 'beta']);
        $association = CategoryAttributeDefinition::findOrFail($association->id);
        $association->setRelation('category', $category);
        $this->refuse(fn () => $association->update(['include_descendants' => true]), ValidationException::class);
    }

    public function test_activity_mode_ignores_even_incoherent_associations_and_invalid_hierarchy(): void
    {
        $definition = $this->definition('legacy', 'activity');
        $category = $this->category('beta');
        $this->assertSame(['legacy'], $this->codes(null));
        $this->connection->table('category_attribute_definition')->insert([
            'category_id' => $category->id, 'attribute_definition_id' => $definition->id,
        ]);
        Category::whereKey($category->id)->update(['parent_id' => $category->id]);
        $this->assertSame(['legacy'], $this->codes($category->id));
        $this->connection->table('category_attribute_definition')->delete();
        $this->assertSame(['legacy'], $this->codes($category->id));
        $this->assertSame(['legacy'], $this->codes(999999));
    }

    public function test_category_resolution_direct_inherited_false_and_deduplicated(): void
    {
        $root = $this->category();
        $middle = $this->category(parent: $root);
        $leaf = $this->category(parent: $middle);
        $this->assertSame($root->id, $middle->parent->id);
        $this->assertSame($leaf->id, $middle->children()->sole()->id);
        $definition = $this->definition();
        $association = $this->associate($root, $definition);
        $this->assertSame(['fixture'], $this->codes($root->id));
        $this->assertSame([], $this->codes($leaf->id));
        $association->update(['include_descendants' => true]);
        $this->assertSame(['fixture'], $this->codes($leaf->id));
        $this->associate($leaf, $definition);
        $this->assertSame(['fixture'], $this->codes($leaf->id));
        $this->assertSame([], $this->codes($this->category()->id));
        $this->assertSame([], $this->codes(null));
    }

    public function test_transversality_never_neutralizes_definition_activity_and_level(): void
    {
        $category = $this->category(null);
        $this->associate($category, $this->definition('alpha_product'));
        $this->associate($category, $this->definition('transverse_product', activity: null));
        $this->associate($category, $this->definition('alpha_variant', level: 'variant'));
        $this->assertSame(['alpha_product', 'transverse_product'], $this->codes($category->id));
        $this->assertSame(['transverse_product'], $this->codes($category->id, 'beta'));
        // color est la définition historique transversale de niveau variante.
        $this->assertSame(['alpha_variant', 'color'], $this->codes($category->id, level: 'variant'));
    }

    public function test_raw_incoherent_association_cannot_grant_another_activity(): void
    {
        $category = $this->category('beta');
        $definition = $this->definition();
        $this->connection->table('category_attribute_definition')->insert([
            'category_id' => $category->id, 'attribute_definition_id' => $definition->id,
        ]);
        $this->assertSame([], $this->codes($category->id, 'beta'));
        $this->refuse(fn () => $this->codes($category->id, 'alpha'));
    }

    public function test_missing_parent_is_refused_without_partial_result(): void
    {
        $this->definition('legacy', 'activity');
        $category = $this->category();
        $this->associate($category, $this->definition());
        if ($this->connection->getDriverName() === 'sqlite') {
            $this->connection->statement('PRAGMA defer_foreign_keys = ON');
        } else {
            $this->connection->statement('ALTER TABLE categories ALTER CONSTRAINT categories_parent_id_foreign DEFERRABLE INITIALLY DEFERRED');
        }
        Category::whereKey($category->id)->update(['parent_id' => 999999]);
        $this->refuse(fn () => $this->codes($category->id));
    }

    public static function invalidContexts(): array
    {
        return [['missing'], ['wrong_activity'], ['wrong_ancestor'], ['self_cycle'], ['multiple_cycle'], ['blank_activity'], ['wrong_level']];
    }

    #[DataProvider('invalidContexts')]
    public function test_invalid_context_refuses_entire_resolution(string $case): void
    {
        $this->definition('legacy', 'activity');
        $definition = $this->definition();
        $root = $this->category();
        $leaf = $this->category(parent: $root);
        $this->associate($root, $definition, true);
        $id = $leaf->id;
        if ($case === 'missing') {
            $id = 999999;
        } elseif ($case === 'wrong_activity') {
            Category::whereKey($leaf->id)->update(['activity' => 'beta']);
        } elseif ($case === 'wrong_ancestor') {
            Category::whereKey($root->id)->update(['activity' => 'beta']);
        } elseif ($case === 'self_cycle') {
            Category::whereKey($leaf->id)->update(['parent_id' => $leaf->id]);
        } elseif ($case === 'multiple_cycle') {
            Category::whereKey($root->id)->update(['parent_id' => $leaf->id]);
        }
        $this->refuse(fn () => $this->codes($id, $case === 'blank_activity' ? ' ' : 'alpha',
            $case === 'wrong_level' ? 'invalid' : 'product'));
    }

    public function test_read_only_resolution_keeps_data_and_global_options_required_unchanged(): void
    {
        $category = $this->category();
        $definition = $this->definition();
        $definition->update(['input_type' => 'select', 'options' => ['A', 'B'], 'is_required' => true]);
        $this->associate($category, $definition);
        $before = $this->definitionRows();
        $this->connection->enableQueryLog();
        $result = (new AttributeApplicabilityResolver)->resolve('alpha', $category->id, 'product');
        $log = $this->connection->getQueryLog();
        $this->connection->disableQueryLog();
        $this->assertTrue($result->sole()->is_required);
        $this->assertSame(['A', 'B'], $result->sole()->options);
        $this->assertNotEmpty($log);
        foreach ($log as $query) {
            $this->assertMatchesRegularExpression('/\Aselect\b/i', $query['query']);
        }
        $this->assertSame($before, $this->definitionRows());
        $this->assertSame($this->historicalValues, $this->valueRows());
    }

    public function test_unknown_mode_is_defensively_refused(): void
    {
        if ($this->connection->getDriverName() === 'sqlite') {
            $this->connection->statement('PRAGMA ignore_check_constraints = ON');
        } else {
            // Corruption de fixture dans une transaction jetable uniquement.
            $this->connection->statement('ALTER TABLE attribute_definitions DROP CONSTRAINT attribute_definitions_applicability_mode_check');
        }
        $this->definition('corrupt', 'unknown');
        $this->refuse(fn () => $this->codes(null));
    }

    public function test_down_always_refuses_before_any_database_operation(): void
    {
        $category = $this->category();
        $this->associate($category, $this->definition());
        $this->connection->enableQueryLog();
        try {
            $this->migration()->down();
            $this->fail('down devait être refusé.');
        } catch (RuntimeException $error) {
            $this->assertNull($error->getPrevious());
        }
        $this->assertSame([], $this->connection->getQueryLog());
        $this->connection->disableQueryLog();
        $this->assertSame(1, CategoryAttributeDefinition::count());
        $this->assertSame('category', AttributeDefinition::where('code', 'fixture')->value('applicability_mode'));
    }
}
