<?php

namespace Tests\Feature;

use App\Filament\Resources\StockMovements\Pages\CreateStockMovement;
use App\Filament\Resources\StockMovements\Pages\EditStockMovement;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Club;
use App\Models\Competition;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariantAttributeValue;
use Database\Seeders\AttributeDefinitionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    public static function creationAtomicityContexts(): array
    {
        $cases = [];
        foreach (['create', 'save', 'filament', 'outer'] as $entry) {
            foreach ([false, true] as $variant) {
                foreach ([false, true] as $warehouseExists) {
                    foreach ([false, true] as $seeded) {
                        $cases[$entry.' variant='.(int) $variant.' warehouse='.(int) $warehouseExists.' mirrors='.(int) $seeded]
                            = [$entry, $variant, $warehouseExists, $seeded];
                    }
                }
            }
        }

        return $cases;
    }

    private function movementCreationSnapshot(): array
    {
        $snapshot = [];
        foreach (['products', 'product_variants', 'warehouse_stocks', 'stock_movements', 'product_attribute_values', 'product_variant_attribute_values'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    public static function explicitPairContexts(): array
    {
        $cases = [];
        foreach ([false, true] as $outer) {
            foreach ([false, true] as $warehouseExists) {
                foreach ([false, true] as $stringIds) {
                    $cases['outer='.(int) $outer.' warehouse='.(int) $warehouseExists.' strings='.(int) $stringIds]
                        = [$outer, $warehouseExists, $stringIds];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('explicitPairContexts')]
    public function test_creation_paire_explicite_incoherente_refusee_avant_toute_ecriture(
        bool $outer, bool $warehouseExists, bool $stringIds,
    ): void {
        $this->seed(AttributeDefinitionSeeder::class);
        $a = $this->makeProduct(['activity' => 'sport', 'stock' => 11]);
        $b = $this->makeProduct(['activity' => 'sport']);
        $variant = $this->makeVariant($b, ['stock' => 5, 'color' => 'Bleu', 'version' => 'Home']);
        $sibling = $this->makeVariant($b, ['stock' => 4]);
        $warehouse = Warehouse::where('is_default', true)->sole();
        if ($warehouseExists) {
            WarehouseStock::create([
                'warehouse_id' => $warehouse->id, 'product_id' => $a->id,
                'product_variant_id' => null, 'stock' => 11,
            ]);
            WarehouseStock::create([
                'warehouse_id' => $warehouse->id, 'product_id' => $b->id,
                'product_variant_id' => $variant->id, 'stock' => 5,
            ]);
        }
        // Des miroirs volontairement différents rendent tout dual-write détectable.
        DB::table('product_attribute_values')->update(['value' => 'PREVIOUS']);
        DB::table('product_variant_attribute_values')->update(['value' => 'PREVIOUS']);
        $this->assertGreaterThan(0, ProductAttributeValue::count());
        $this->assertGreaterThan(0, ProductVariantAttributeValue::count());
        $connection = DB::connection();
        $initialLevel = $connection->transactionLevel();
        $baseline = $this->movementCreationSnapshot();
        $this->travel(2)->seconds();
        try {
            if ($outer) {
                $connection->beginTransaction();
                $prior = $this->makeProduct(['stock' => 13]);
                $outerSnapshot = $this->movementCreationSnapshot();
                $connection->beginTransaction();
                $this->makeProduct(['stock' => 14]);
            }
            $operationLevel = $connection->transactionLevel();
            $before = $this->movementCreationSnapshot();
            $movement = new StockMovement([
                'product_id' => $stringIds ? (string) $a->id : $a->id,
                'product_variant_id' => $stringIds ? (string) $variant->id : $variant->id,
                'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 2,
            ]);
            $identifiers = $movement->only(['product_id', 'product_variant_id']);
            $originalLog = $connection->getQueryLog();
            $wasLogging = $connection->logging();
            $connection->enableQueryLog();
            $caught = null;
            try {
                $movement->save();
            } catch (\Exception $exception) {
                $caught = $exception;
            } finally {
                $queries = array_slice($connection->getQueryLog(), count($originalLog));
                (new \ReflectionProperty($connection, 'queryLog'))->setValue($connection, $originalLog);
                $wasLogging ? $connection->enableQueryLog() : $connection->disableQueryLog();
            }
            $this->assertNotNull($caught);
            $this->assertSame(\Exception::class, $caught::class);
            $this->assertSame('La variante sélectionnée n\'appartient pas au produit indiqué.', $caught->getMessage());
            $this->assertSame([], array_values(array_filter($queries, static fn (array $query): bool =>
                preg_match('/^\s*(?:insert|update|delete|replace)\b/i', $query['query']) === 1
            )), 'Le refus précède toute écriture, pas seulement un rollback des effets.');
            $this->assertFalse($movement->exists);
            $this->assertSame($identifiers, $movement->only(['product_id', 'product_variant_id']));
            $this->assertSame($operationLevel, $connection->transactionLevel());
            $this->assertSame($before, $this->movementCreationSnapshot(), 'Toutes les lignes, miroirs et timestamps inchangés avant teardown.');
            $this->assertSame(0, StockMovement::count());
            $this->assertSame(11, (int) $a->fresh()->stock);
            $this->assertSame(9, (int) $b->fresh()->stock);
            $this->assertSame(5, (int) $variant->fresh()->stock);
            $this->assertSame(4, (int) $sibling->fresh()->stock);
            if ($outer) {
                $this->assertSame(13, (int) $prior->fresh()->stock);
            }

            // Même instance après refus : seuls les identifiants corrigés explicitement changent.
            $movement->product_id = $stringIds ? (string) $b->id : $b->id;
            $this->assertTrue($movement->save());
            $this->assertSame($b->id, (int) $movement->fresh()->product_id);
            $this->assertSame($variant->id, (int) $movement->fresh()->product_variant_id);
            $this->assertSame(7, (int) $variant->fresh()->stock);
            $this->assertSame(11, (int) $b->fresh()->stock);
            $this->assertSame(7, (int) WarehouseStock::where('warehouse_id', $warehouse->id)
                ->where('product_id', $b->id)->where('product_variant_id', $variant->id)->sole()->stock);

            $plain = StockMovement::create([
                'product_id' => $a->id, 'warehouse_id' => $warehouse->id,
                'type' => 'purchase', 'quantity' => 3,
            ]);
            $this->assertNull($plain->fresh()->product_variant_id);
            $this->assertSame(14, (int) $a->fresh()->stock);
            $this->assertSame($operationLevel, $connection->transactionLevel());
            if ($outer) {
                $connection->rollBack();
                $this->assertSame($initialLevel + 1, $connection->transactionLevel());
                $this->assertSame($outerSnapshot, $this->movementCreationSnapshot());
                $connection->rollBack();
                $this->assertSame($baseline, $this->movementCreationSnapshot());
            }
            $this->assertSame($initialLevel, $connection->transactionLevel());
        } finally {
            while ($connection->transactionLevel() > $initialLevel) {
                $connection->rollBack();
            }
            $this->travelBack();
        }
    }

    public static function deletionAtomicityContexts(): array
    {
        $cases = [];
        foreach (['delete', 'deleteOrFail', 'outer', 'filament'] as $entry) {
            foreach ([false, true] as $variant) {
                foreach ([false, true] as $mirrors) {
                    $cases[$entry.' variant='.(int) $variant.' mirrors='.(int) $mirrors]
                        = [$entry, $variant, $mirrors];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('deletionAtomicityContexts')]
    public function test_suppression_stock_movement_atomique_annule_delete_final_et_preserve_succes(
        string $entry, bool $withVariant, bool $mirrors,
    ): void {
        if ($mirrors) {
            $this->seed(AttributeDefinitionSeeder::class);
        }
        $a = Warehouse::where('is_default', true)->sole();
        $b = Warehouse::create(['name' => 'B', 'code' => 'cp2621-b']);
        $product = $this->makeProduct([
            'activity' => 'sport', 'stock' => 5, 'taille' => 'M', 'equipe' => 'Equipe', 'season' => '2025-2026',
        ]);
        $variant = $withVariant ? $this->makeVariant($product, [
            'stock' => 5, 'size' => 'M', 'color' => 'Bleu', 'version' => 'Home',
        ]) : null;
        $sibling = $withVariant ? $this->makeVariant($product, ['stock' => 4]) : null;
        $key = ['product_id' => $product->id, 'product_variant_id' => $variant?->id];
        $anchor = StockMovement::create($key + ['warehouse_id' => $a->id, 'type' => 'purchase', 'quantity' => 2]);
        $movement = StockMovement::create($key + ['warehouse_id' => $a->id, 'type' => 'purchase', 'quantity' => 10]);
        $neighbor = StockMovement::create($key + ['warehouse_id' => $b->id, 'type' => 'purchase', 'quantity' => 3]);
        $sale = StockMovement::create($key + ['warehouse_id' => $a->id, 'type' => 'sale', 'quantity' => 4]);
        $mirrorTable = $withVariant ? 'product_variant_attribute_values' : 'product_attribute_values';
        $mirrorKey = $withVariant ? 'product_variant_id' : 'product_id';
        $targetId = $variant?->id ?? $product->id;
        $targetTable = $withVariant ? 'product_variants' : 'products';
        if ($mirrors) {
            $this->assertSame(3, DB::table($mirrorTable)->where($mirrorKey, $targetId)->count());
            DB::table($mirrorTable)->where($mirrorKey, $targetId)->update(['value' => 'PREVIOUS']);
        }
        if ($entry === 'filament') {
            $this->seed(RoleSeeder::class);
            $this->actingAs(User::factory()->create()->assignRole('admin'));
        }
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getDriverName());
        $initialLevel = $connection->transactionLevel();
        $baseline = $this->movementCreationSnapshot();
        $originalLog = $connection->getQueryLog();
        $wasLogging = $connection->logging();
        $dispatcher = StockMovement::getEventDispatcher();
        StockMovement::setEventDispatcher(clone $dispatcher);
        $events = [];
        $operationLevel = $initialLevel;
        StockMovement::deleting(function (StockMovement $record) use (&$events, &$operationLevel, $connection, $movement): void {
            if ($record->id === $movement->id) {
                $this->assertGreaterThan($operationLevel, $connection->transactionLevel());
                $events[] = 'deleting';
            }
        });
        StockMovement::deleted(function (StockMovement $record) use (&$events, &$operationLevel, $connection, $movement): void {
            if ($record->id === $movement->id) {
                $this->assertGreaterThan($operationLevel, $connection->transactionLevel());
                $this->assertFalse($record->exists);
                $this->assertDatabaseMissing('stock_movements', ['id' => $record->id]);
                $events[] = 'deleted';
            }
        });
        $run = function () use ($entry, $movement): void {
            $fresh = $movement->fresh();
            if ($entry === 'filament') {
                $page = Livewire::test(EditStockMovement::class, ['record' => $fresh->getRouteKey()]);
                $this->assertFalse($page->instance()->hasDatabaseTransactions());
                $page->callAction('delete');
            } else {
                $this->assertTrue($entry === 'deleteOrFail' ? $fresh->deleteOrFail() : $fresh->delete());
                $this->assertFalse($fresh->exists);
            }
        };
        $this->withoutExceptionHandling();
        $this->travel(2)->seconds();
        try {
            if ($entry === 'outer') {
                $connection->beginTransaction();
                $this->makeProduct(['stock' => 11]);
                $outerSnapshot = $this->movementCreationSnapshot();
                $connection->beginTransaction();
                $this->makeProduct(['stock' => 12]);
            }
            $operationLevel = $connection->transactionLevel();
            $before = $this->movementCreationSnapshot();
            $parentStock = $withVariant ? 10 : 6;
            $mirrorCondition = $mirrors
                ? "AND (SELECT COUNT(*) FROM {$mirrorTable} WHERE {$mirrorKey} = {$targetId} AND value <> 'PREVIOUS') = 3"
                : '';
            // La sonde refuse le DELETE seulement après vérification des effets réels en DB.
            $connection->unprepared("CREATE TEMP TRIGGER checkpoint_2621_failure BEFORE DELETE ON stock_movements
                WHEN OLD.id = {$movement->id}
                BEGIN SELECT CASE WHEN
                    (SELECT stock_before FROM stock_movements WHERE id = {$neighbor->id}) = 7
                    AND (SELECT stock_after FROM stock_movements WHERE id = {$sale->id}) = 6
                    AND (SELECT stock FROM {$targetTable} WHERE id = {$targetId}) = 6
                    AND (SELECT stock FROM products WHERE id = {$product->id}) = {$parentStock}
                    AND (SELECT SUM(stock) FROM warehouse_stocks WHERE product_id = {$product->id}
                        AND product_variant_id ".($variant ? '= '.$variant->id : 'IS NULL').") = 6
                    {$mirrorCondition}
                    THEN RAISE(ABORT, 'checkpoint_2621_final_delete_failure')
                    ELSE RAISE(ABORT, 'checkpoint_2621_replay_missing') END; END");
            $offset = count($connection->getQueryLog());
            $connection->enableQueryLog();
            $caught = null;
            try {
                $run();
            } catch (QueryException $exception) {
                $caught = $exception;
            } finally {
                $queries = array_slice($connection->getQueryLog(), $offset);
                $connection->unprepared('DROP TRIGGER IF EXISTS temp.checkpoint_2621_failure');
                (new \ReflectionProperty($connection, 'queryLog'))->setValue($connection, $originalLog);
                $wasLogging ? $connection->enableQueryLog() : $connection->disableQueryLog();
            }
            $this->assertNotNull($caught);
            $this->assertStringContainsString('checkpoint_2621_final_delete_failure', $caught->getMessage());
            $this->assertStringStartsWith('delete from "stock_movements"', $caught->getSql());
            $this->assertSame([$movement->id], $caught->getBindings());
            $this->assertSame(['deleting'], $events);
            $this->assertSame($operationLevel, $connection->transactionLevel());
            $this->assertSame($before, $this->movementCreationSnapshot(), 'Toutes les lignes DB et timestamps restaurés avant teardown.');
            $this->assertSame(10, $movement->fresh()->quantity);
            $this->assertSame(17, $neighbor->fresh()->stock_before);
            $this->assertSame(16, (int) ($variant ?? $product)->fresh()->stock);
            $this->assertSame($withVariant ? 20 : 16, (int) $product->fresh()->stock);
            $tables = [];
            foreach ($queries as $query) {
                if (preg_match('/^update "(stock_movements|products|product_variants|warehouse_stocks|product_attribute_values|product_variant_attribute_values)"/', $query['query'], $match) === 1) {
                    $tables[] = $match[1];
                    $this->assertStringContainsString('updated_at', $query['query']);
                    $this->assertContains(now()->format('Y-m-d H:i:s'), $query['bindings']);
                }
            }
            $expected = ['stock_movements', 'stock_movements'];
            $expected = [...$expected, ...($withVariant ? ['product_variants', 'products'] : ['products'])];
            if ($mirrors) {
                $expected = [...$expected, ...array_fill(0, 3, $mirrorTable)];
            }
            $this->assertSame([...$expected, 'warehouse_stocks', 'warehouse_stocks'], $tables);
            $run();
            $this->assertSame(['deleting', 'deleting', 'deleted'], $events);
            $this->assertSame($operationLevel, $connection->transactionLevel());
            $this->assertNull($movement->fresh());
            $this->assertSame(5, $anchor->fresh()->stock_before);
            $this->assertSame(7, $neighbor->fresh()->stock_before);
            $this->assertSame(10, $sale->fresh()->stock_before);
            $this->assertSame(6, (int) ($variant ?? $product)->fresh()->stock);
            $this->assertSame($parentStock, (int) $product->fresh()->stock);
            $this->assertSame(3, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $a->id)->value('stock'));
            $this->assertSame(3, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $b->id)->value('stock'));
            if ($sibling) {
                $this->assertSame(4, (int) $sibling->fresh()->stock);
            }
            if ($entry === 'outer') {
                $connection->rollBack();
                $this->assertSame($initialLevel + 1, $connection->transactionLevel());
                $this->assertSame($outerSnapshot, $this->movementCreationSnapshot());
                $connection->rollBack();
                $this->assertSame($baseline, $this->movementCreationSnapshot());
            }
        } finally {
            $connection->unprepared('DROP TRIGGER IF EXISTS temp.checkpoint_2621_failure');
            (new \ReflectionProperty($connection, 'queryLog'))->setValue($connection, $originalLog);
            $wasLogging ? $connection->enableQueryLog() : $connection->disableQueryLog();
            StockMovement::setEventDispatcher($dispatcher);
            $connection->rollBack($initialLevel);
            $this->travelBack();
            $this->withExceptionHandling();
        }
        $this->assertSame($initialLevel, $connection->transactionLevel());
        $this->assertSame($originalLog, $connection->getQueryLog());
        $this->assertSame($wasLogging, $connection->logging());
        $this->assertSame([], $connection->select("SELECT name FROM sqlite_temp_master WHERE type = 'trigger' AND name = 'checkpoint_2621_failure'"));
        $this->assertNull((new StockMovement)->delete());
        $this->assertSame($initialLevel, $connection->transactionLevel());
    }

    public static function deletionBusinessFailureContexts(): array
    {
        return [
            'produit direct' => [false, false],
            'produit englobant' => [false, true],
            'variante directe' => [true, false],
            'variante englobante' => [true, true],
        ];
    }

    #[DataProvider('deletionBusinessFailureContexts')]
    public function test_suppression_stock_movement_preserve_exception_metier_et_niveau_transactionnel(
        bool $withVariant, bool $outer,
    ): void {
        $this->seed(AttributeDefinitionSeeder::class);
        $product = $this->makeProduct(['activity' => 'sport']);
        $variant = $withVariant ? $this->makeVariant($product) : null;
        $key = ['product_id' => $product->id, 'product_variant_id' => $variant?->id];
        $purchase = StockMovement::create($key + ['type' => 'purchase', 'quantity' => 10]);
        StockMovement::create($key + ['type' => 'sale', 'quantity' => 8]);
        $connection = DB::connection();
        $initialLevel = $connection->transactionLevel();
        try {
            if ($outer) {
                $connection->beginTransaction();
                $this->makeProduct(['stock' => 11]);
            }
            $before = $this->movementCreationSnapshot();
            $operationLevel = $connection->transactionLevel();
            $caught = null;
            try {
                $purchase->delete();
            } catch (\Exception $exception) {
                $caught = $exception;
            }
            $this->assertNotNull($caught);
            $this->assertSame(\Exception::class, $caught::class);
            $this->assertSame('Stock insuffisant pour effectuer cette vente.', $caught->getMessage());
            $this->assertSame($operationLevel, $connection->transactionLevel());
            $this->assertTrue($purchase->exists);
            $this->assertSame($before, $this->movementCreationSnapshot());
        } finally {
            $connection->rollBack($initialLevel);
        }
        $this->assertSame($initialLevel, $connection->transactionLevel());
    }

    public function test_suppression_stock_movement_conserve_retour_false_si_evenement_annule(): void
    {
        $product = $this->makeProduct();
        $movement = StockMovement::create(['product_id' => $product->id, 'type' => 'purchase', 'quantity' => 2]);
        $dispatcher = StockMovement::getEventDispatcher();
        StockMovement::setEventDispatcher(clone $dispatcher);
        $level = DB::connection()->transactionLevel();
        try {
            StockMovement::deleting(static fn (StockMovement $record): bool => false);
            $this->assertFalse($movement->delete());
            $this->assertTrue($movement->exists);
            $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
            $this->assertSame($level, DB::connection()->transactionLevel());
        } finally {
            StockMovement::setEventDispatcher($dispatcher);
        }
    }

    public static function updateAtomicityContexts(): array
    {
        $cases = [];
        foreach (['update', 'save', 'outer', 'filament'] as $entry) {
            foreach ([false, true] as $variant) {
                foreach ([false, true] as $mirrors) {
                    $cases[$entry.' variant='.(int) $variant.' mirrors='.(int) $mirrors]
                        = [$entry, $variant, $mirrors];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('updateAtomicityContexts')]
    public function test_modification_stock_movement_atomique_annule_update_final_et_preserve_succes(
        string $entry, bool $withVariant, bool $mirrors,
    ): void {
        if ($mirrors) {
            $this->seed(AttributeDefinitionSeeder::class);
        }
        $warehouseA = Warehouse::where('is_default', true)->sole();
        $warehouseB = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'cp2619-b']);
        $product = $this->makeProduct([
            'activity' => 'sport', 'stock' => 5, 'taille' => 'M', 'equipe' => 'Equipe', 'season' => '2025-2026',
        ]);
        $variant = $withVariant ? $this->makeVariant($product, [
            'stock' => 5, 'size' => 'M', 'color' => 'Bleu', 'version' => 'Home',
        ]) : null;
        $sibling = $withVariant ? $this->makeVariant($product, ['stock' => 4]) : null;
        $key = ['product_id' => $product->id, 'product_variant_id' => $variant?->id];
        WarehouseStock::create($key + ['warehouse_id' => $warehouseA->id, 'stock' => 5]);
        WarehouseStock::create($key + ['warehouse_id' => $warehouseB->id, 'stock' => 0]);
        $anchor = StockMovement::create($key + ['warehouse_id' => $warehouseA->id, 'type' => 'purchase', 'quantity' => 2]);
        $movement = StockMovement::create($key + ['warehouse_id' => $warehouseA->id, 'type' => 'purchase', 'quantity' => 10]);
        $neighbor = StockMovement::create($key + ['warehouse_id' => $warehouseB->id, 'type' => 'purchase', 'quantity' => 3]);
        $sale = StockMovement::create($key + ['warehouse_id' => $warehouseA->id, 'type' => 'sale', 'quantity' => 4]);
        $untouched = $this->makeProduct(['stock' => 11]);
        $mirrorTable = $withVariant ? 'product_variant_attribute_values' : 'product_attribute_values';
        $mirrorKey = $withVariant ? 'product_variant_id' : 'product_id';
        $targetId = $variant?->id ?? $product->id;
        if ($mirrors) {
            // Fixtures volontairement décalées : le rejeu doit réellement écrire les miroirs.
            $this->assertSame(3, DB::table($mirrorTable)->where($mirrorKey, $targetId)->count());
            DB::table($mirrorTable)->where($mirrorKey, $targetId)->update(['value' => 'PREVIOUS']);
        }
        if ($entry === 'filament') {
            $this->seed(RoleSeeder::class);
            $this->actingAs(User::factory()->create()->assignRole('admin'));
        }
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getDriverName());
        foreach ([StockMovement::class, Product::class, ProductVariant::class, WarehouseStock::class, ProductAttributeValue::class, ProductVariantAttributeValue::class] as $model) {
            $this->assertSame($connection, (new $model)->getConnection());
        }
        $initialLevel = $connection->transactionLevel();
        $baseline = $this->movementCreationSnapshot();
        $originalLog = $connection->getQueryLog();
        $wasLogging = $connection->logging();
        $run = function () use ($entry, $movement): void {
            // Toujours relire la DB : le rollback ne restaure pas les objets PHP.
            $fresh = $movement->fresh();
            if ($entry === 'filament') {
                $page = Livewire::test(EditStockMovement::class, ['record' => $fresh->getRouteKey()]);
                $this->assertFalse($page->instance()->hasDatabaseTransactions(), 'La protection doit venir du modèle.');
                $page->fillForm(['quantity' => 20])->call('save')->assertHasNoFormErrors();
            } elseif ($entry === 'save') {
                $fresh->quantity = 20;
                $this->assertTrue($fresh->save());
            } else {
                $this->assertTrue($fresh->update(['quantity' => 20]));
            }
        };
        $this->withoutExceptionHandling();
        $this->travel(2)->seconds();
        try {
            if ($entry === 'outer') {
                $connection->beginTransaction();
                DB::table('products')->where('id', $untouched->id)->update(['nom' => 'Écriture englobante']);
                $outerSnapshot = $this->movementCreationSnapshot();
                $connection->beginTransaction();
                DB::table('products')->where('id', $untouched->id)->update(['nom' => 'Écriture savepoint']);
            }
            $operationLevel = $connection->transactionLevel();
            $before = $this->movementCreationSnapshot();
            $caught = null;
            $queries = [];
            // Cible seulement l'UPDATE final du mouvement édité, pas les saveQuietly des voisins.
            $connection->unprepared("CREATE TEMP TRIGGER checkpoint_2619_failure BEFORE UPDATE ON stock_movements
                WHEN OLD.id = {$movement->id} AND NEW.quantity = 20
                BEGIN SELECT RAISE(ABORT, 'checkpoint_2619_final_update_failure'); END");
            $offset = count($connection->getQueryLog());
            $connection->enableQueryLog();
            try {
                $run();
            } catch (QueryException $exception) {
                $caught = $exception;
            } finally {
                $queries = array_slice($connection->getQueryLog(), $offset);
                $connection->unprepared('DROP TRIGGER IF EXISTS temp.checkpoint_2619_failure');
                (new \ReflectionProperty($connection, 'queryLog'))->setValue($connection, $originalLog);
                $wasLogging ? $connection->enableQueryLog() : $connection->disableQueryLog();
            }
            $this->assertNotNull($caught);
            $this->assertStringContainsString('checkpoint_2619_final_update_failure', $caught->getMessage());
            $this->assertStringStartsWith('update "stock_movements"', $caught->getSql());
            $bindings = $caught->getBindings();
            $this->assertSame($movement->id, $bindings[array_key_last($bindings)]);
            $this->assertContains('20', array_map('strval', $bindings));
            $this->assertContains(27, $bindings, 'Le mouvement en cours porte le résultat du rejeu.');
            $this->assertSame($operationLevel, $connection->transactionLevel());
            $this->assertSame($before, $this->movementCreationSnapshot(), 'Toutes les colonnes DB et timestamps avant teardown.');
            $this->assertSame(10, $movement->fresh()->quantity);
            $this->assertSame(7, $movement->fresh()->stock_before);
            $this->assertSame(17, $movement->fresh()->stock_after);
            $this->assertSame(17, $neighbor->fresh()->stock_before);
            $this->assertSame(20, $sale->fresh()->stock_before);
            $this->assertSame(16, (int) ($variant ?? $product)->fresh()->stock);
            $this->assertSame($withVariant ? 20 : 16, (int) $product->fresh()->stock);
            $this->assertSame(13, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $warehouseA->id)->value('stock'));
            $this->assertSame(3, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $warehouseB->id)->value('stock'));

            $writes = [];
            foreach ($queries as $query) {
                $sql = strtolower(str_replace('"', '', $query['query']));
                if (preg_match('/^update (stock_movements|products|product_variants|warehouse_stocks|product_attribute_values|product_variant_attribute_values)\b/', $sql, $match) === 1) {
                    $this->assertStringContainsString('updated_at', $sql);
                    $this->assertContains(now()->format('Y-m-d H:i:s'), $query['bindings']);
                    $writes[] = [$match[1], $query['bindings']];
                }
            }
            $expectedTables = ['stock_movements', 'stock_movements'];
            $expectedTables = [...$expectedTables, ...($withVariant ? ['product_variants', 'products'] : ['products'])];
            if ($mirrors) {
                $expectedTables = [...$expectedTables, ...array_fill(0, 3, $mirrorTable)];
            }
            $expectedTables = [...$expectedTables, 'warehouse_stocks', 'warehouse_stocks'];
            $this->assertSame($expectedTables, array_column($writes, 0), 'Écritures SQL réussies avant l’UPDATE final rejeté.');
            $this->assertContains(27, $writes[0][1]);
            $this->assertContains(30, $writes[0][1]);
            $this->assertSame($neighbor->id, $writes[0][1][array_key_last($writes[0][1])]);
            $this->assertContains(26, $writes[1][1]);
            $this->assertSame($sale->id, $writes[1][1][array_key_last($writes[1][1])]);
            $this->assertContains(26, $writes[2][1]);
            if ($withVariant) {
                $this->assertContains(30, $writes[3][1], 'Le parent a réellement été recalculé.');
            }
            $this->assertContains(23, $writes[count($writes) - 2][1]);
            $this->assertContains(3, $writes[count($writes) - 1][1]);
            $this->assertSame($wasLogging, $connection->logging());
            $this->assertSame($originalLog, $connection->getQueryLog());

            $run();
            $this->assertSame($operationLevel, $connection->transactionLevel());
            $this->assertSame(20, $movement->fresh()->quantity);
            $this->assertSame(7, $movement->fresh()->stock_before);
            $this->assertSame(27, $movement->fresh()->stock_after);
            $this->assertSame(27, $neighbor->fresh()->stock_before);
            $this->assertSame(30, $neighbor->fresh()->stock_after);
            $this->assertSame(30, $sale->fresh()->stock_before);
            $this->assertSame(26, $sale->fresh()->stock_after);
            $this->assertSame(5, $anchor->fresh()->stock_before);
            $this->assertSame(7, $anchor->fresh()->stock_after);
            $this->assertSame(26, (int) ($variant ?? $product)->fresh()->stock);
            $this->assertSame($withVariant ? 30 : 26, (int) $product->fresh()->stock);
            if ($sibling) {
                $this->assertSame(4, (int) $sibling->fresh()->stock);
            }
            $this->assertSame(23, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $warehouseA->id)->value('stock'));
            $this->assertSame(3, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $warehouseB->id)->value('stock'));
            $this->assertSame(11, (int) $untouched->fresh()->stock);
            if ($mirrors) {
                $values = $withVariant ? ['size' => 'M', 'color' => 'Bleu', 'version' => 'Home']
                    : ['season' => '2025-2026', 'taille' => 'M', 'equipe' => 'Equipe'];
                $rows = DB::table($mirrorTable)->where($mirrorKey, $targetId)->get();
                $codes = DB::table('attribute_definitions')->pluck('code', 'id');
                $this->assertCount(3, $rows);
                foreach ($rows as $row) {
                    $this->assertSame($values[$codes[$row->attribute_definition_id]], $row->value);
                    $this->assertSame(now()->format('Y-m-d H:i:s'), $row->updated_at);
                }
            }
            if ($entry === 'outer') {
                $connection->rollBack();
                $this->assertSame($initialLevel + 1, $connection->transactionLevel());
                $this->assertSame($outerSnapshot, $this->movementCreationSnapshot(), 'Le savepoint englobant reste utilisable après échec puis succès.');
                $connection->rollBack();
                $this->assertSame($baseline, $this->movementCreationSnapshot());
            }
        } finally {
            $connection->unprepared('DROP TRIGGER IF EXISTS temp.checkpoint_2619_failure');
            (new \ReflectionProperty($connection, 'queryLog'))->setValue($connection, $originalLog);
            $wasLogging ? $connection->enableQueryLog() : $connection->disableQueryLog();
            $connection->rollBack($initialLevel);
            $this->travelBack();
            $this->withExceptionHandling();
        }
        $this->assertSame($initialLevel, $connection->transactionLevel());
        $this->assertSame([], $connection->select("SELECT name FROM sqlite_temp_master WHERE type = 'trigger' AND name = 'checkpoint_2619_failure'"));
    }

    #[DataProvider('creationAtomicityContexts')]
    public function test_creation_stock_movement_atomique_annule_echec_insert_final_et_preserve_succes(
        string $entry, bool $withVariant, bool $warehouseExists, bool $seeded,
    ): void {
        if ($seeded) {
            $this->seed(AttributeDefinitionSeeder::class);
        }
        $warehouse = Warehouse::where('is_default', true)->sole();
        $product = $this->makeProduct([
            'activity' => 'sport', 'stock' => 5, 'taille' => 'M', 'equipe' => 'Equipe', 'season' => '2025-2026',
        ]);
        $variant = $withVariant ? $this->makeVariant($product, [
            'stock' => 5, 'size' => 'M', 'color' => 'Bleu', 'version' => 'Home',
        ]) : null;
        $warehouseKey = ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => $variant?->id];
        if ($warehouseExists) {
            WarehouseStock::create($warehouseKey + ['stock' => 5]);
        }
        if ($seeded) {
            // Miroirs décalés uniquement dans les fixtures : forcer des écritures réelles des hooks.
            DB::table($withVariant ? 'product_variant_attribute_values' : 'product_attribute_values')
                ->where($withVariant ? 'product_variant_id' : 'product_id', $variant?->id ?? $product->id)
                ->update(['value' => 'PREVIOUS']);
        }
        $untouched = $this->makeProduct(['stock' => 11]);
        $data = $warehouseKey + ['type' => 'purchase', 'quantity' => 2, 'reference' => 'CP2617-FAIL'];
        if ($entry === 'filament') {
            $this->seed(RoleSeeder::class);
            $this->actingAs(User::factory()->create()->assignRole('admin'));
        }
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getDriverName());
        foreach ([StockMovement::class, Product::class, ProductVariant::class, WarehouseStock::class, ProductAttributeValue::class, ProductVariantAttributeValue::class] as $model) {
            $this->assertSame($connection, (new $model)->getConnection());
        }
        $before = $this->movementCreationSnapshot();
        $initialLevel = $connection->transactionLevel();
        $originalLog = $connection->getQueryLog();
        $wasLogging = $connection->logging();
        $caught = null;
        $queries = [];
        $run = function (array $attributes) use ($entry): void {
            if ($entry === 'save') {
                $this->assertTrue((new StockMovement($attributes))->save());
            } elseif ($entry === 'filament') {
                $page = Livewire::test(CreateStockMovement::class);
                $this->assertFalse($page->instance()->hasDatabaseTransactions(), 'La protection doit venir du modèle.');
                $page->fillForm($attributes)->call('create')->assertHasNoFormErrors();
            } else {
                StockMovement::create($attributes);
            }
        };
        $this->withoutExceptionHandling();
        $this->travel(2)->seconds();
        if ($entry === 'outer') {
            $connection->beginTransaction();
        }
        $operationLevel = $connection->transactionLevel();
        try {
            // Sonde SQLite exclusivement de test : échec de l'INSERT final, après les effets stock.
            $connection->unprepared("CREATE TEMP TRIGGER checkpoint_2617_failure BEFORE INSERT ON stock_movements
                WHEN NEW.reference = 'CP2617-FAIL'
                BEGIN SELECT RAISE(ABORT, 'checkpoint_2617_final_insert_failure'); END");
            $offset = count($connection->getQueryLog());
            $connection->enableQueryLog();
            try {
                $run($data);
            } catch (QueryException $exception) {
                $caught = $exception;
            } finally {
                $queries = array_slice($connection->getQueryLog(), $offset);
                $connection->unprepared('DROP TRIGGER IF EXISTS temp.checkpoint_2617_failure');
                (new \ReflectionProperty($connection, 'queryLog'))->setValue($connection, $originalLog);
                $wasLogging ? $connection->enableQueryLog() : $connection->disableQueryLog();
            }
            $this->assertNotNull($caught);
            $this->assertStringContainsString('checkpoint_2617_final_insert_failure', $caught->getMessage());
            $this->assertStringContainsString('insert into "stock_movements"', $caught->getSql());
            $this->assertContains('CP2617-FAIL', $caught->getBindings());
            $this->assertSame($operationLevel, $connection->transactionLevel());
            $this->assertSame($before, $this->movementCreationSnapshot(), 'Lectures fraîches avant teardown, timestamps inclus.');
            $this->assertDatabaseMissing('stock_movements', ['reference' => 'CP2617-FAIL']);
            $this->assertSame(5, (int) $product->fresh()->stock);
            if ($variant) {
                $this->assertSame(5, (int) $variant->fresh()->stock);
            }
            $this->assertSame($warehouseExists ? 5 : null, DB::table('warehouse_stocks')->where($warehouseKey)->value('stock'));

            $writes = [];
            foreach ($queries as $query) {
                $sql = strtolower(str_replace('"', '', $query['query']));
                if (preg_match('/^(insert into|update) (products|product_variants|warehouse_stocks|product_attribute_values|product_variant_attribute_values)\b/', $sql, $match) === 1) {
                    $writes[] = $match[1].' '.$match[2];
                    if (in_array($match[2], ['products', 'product_variants', 'warehouse_stocks'], true) && $match[1] === 'update') {
                        $this->assertContains(7, $query['bindings'], 'Le stock a réellement été écrit avant l’échec.');
                    }
                }
            }
            $expected = $withVariant ? ['update product_variants', 'update products'] : ['update products'];
            if ($seeded) {
                $expected = [...$expected, ...array_fill(0, 3, 'update '.($withVariant ? 'product_variant_attribute_values' : 'product_attribute_values'))];
            }
            if (! $warehouseExists) {
                $expected[] = 'insert into warehouse_stocks';
            }
            $expected[] = 'update warehouse_stocks';
            $this->assertSame($expected, $writes, 'Ordre des écritures SQL réussies avant l’INSERT rejeté.');
            $this->assertSame($wasLogging, $connection->logging());
            $this->assertSame($originalLog, $connection->getQueryLog());

            $run(array_replace($data, ['reference' => 'CP2617-SUCCESS']));
            $this->assertSame($operationLevel, $connection->transactionLevel());
            $movement = StockMovement::where('reference', 'CP2617-SUCCESS')->sole();
            $this->assertSame(5, $movement->stock_before);
            $this->assertSame(7, $movement->stock_after);
            $this->assertSame(7, (int) $product->fresh()->stock);
            if ($variant) {
                $this->assertSame(7, (int) $variant->fresh()->stock);
            }
            $this->assertSame(7, (int) DB::table('warehouse_stocks')->where($warehouseKey)->value('stock'));
            $this->assertSame(11, (int) $untouched->fresh()->stock);
            foreach (['product_attribute_values', 'product_variant_attribute_values'] as $table) {
                $after = $this->movementCreationSnapshot()[$table];
                $withoutTimestamps = static fn (array $rows): array => array_map(static function (array $row): array {
                    unset($row['created_at'], $row['updated_at']);
                    return $row;
                }, $rows);
                $expectedRows = $before[$table];
                if ($seeded && $table === ($withVariant ? 'product_variant_attribute_values' : 'product_attribute_values')) {
                    $values = $withVariant ? ['size' => 'M', 'color' => 'Bleu', 'version' => 'Home']
                        : ['season' => '2025-2026', 'taille' => 'M', 'equipe' => 'Equipe'];
                    $codes = DB::table('attribute_definitions')->pluck('code', 'id');
                    foreach ($expectedRows as &$row) {
                        if ($row[$withVariant ? 'product_variant_id' : 'product_id'] === ($variant?->id ?? $product->id)) {
                            $row['value'] = $values[$codes[$row['attribute_definition_id']]];
                        }
                    }
                    unset($row);
                }
                $this->assertSame($withoutTimestamps($expectedRows), $withoutTimestamps($after));
            }
        } finally {
            $connection->unprepared('DROP TRIGGER IF EXISTS temp.checkpoint_2617_failure');
            (new \ReflectionProperty($connection, 'queryLog'))->setValue($connection, $originalLog);
            $wasLogging ? $connection->enableQueryLog() : $connection->disableQueryLog();
            if ($entry === 'outer') {
                $connection->rollBack();
            }
            $this->travelBack();
            $this->withExceptionHandling();
        }
        $this->assertSame($initialLevel, $connection->transactionLevel());
        if ($entry === 'outer') {
            $this->assertSame($before, $this->movementCreationSnapshot(), 'Le succès reste annulable par la transaction englobante.');
        }
        $this->assertSame([], $connection->select("SELECT name FROM sqlite_temp_master WHERE type = 'trigger' AND name = 'checkpoint_2617_failure'"));
    }

    /**
     * Étape T11b : StockMovement::creating() résout désormais
     * systématiquement un entrepôt (par défaut en repli) — un
     * entrepôt par défaut doit donc exister pour que les mouvements
     * de ce fichier (non liés à la dimension entrepôt elle-même)
     * continuent de se créer normalement.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Warehouse::create([
            'name' => 'Entrepôt par défaut',
            'code' => 'defaut',
            'is_default' => true,
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-' . uniqid(),
            'nom' => 'Maillot Test',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    private function makeVariant(Product $product, array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'SKU-' . uniqid(),
            'size' => 'M',
            'stock' => 0,
            'status' => 'active',
        ], $attributes));
    }

    public function test_un_produit_peut_etre_cree_avec_les_nouvelles_relations_sans_champs_legacy(): void
    {
        $brand = Brand::create(['name' => 'Nike', 'slug' => 'nike']);
        $category = Category::create(['name' => 'Football', 'slug' => 'football']);
        $club = Club::create(['name' => 'Paris Saint-Germain', 'slug' => 'paris-saint-germain']);
        $competition = Competition::create(['name' => 'Ligue 1', 'slug' => 'ligue-1']);
        $supplier = Supplier::create(['name' => 'AliExpress']);

        $product = Product::create([
            'reference' => 'REF-NEW',
            'nom' => 'Maillot PSG',
            'type' => 'Player Version',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'club_id' => $club->id,
            'competition_id' => $competition->id,
            'supplier_id' => $supplier->id,
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);

        $this->assertNotNull($product->id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('Football', $product->categorie);
        $this->assertSame('Nike', $product->marque);
        $this->assertSame('AliExpress', $product->fournisseur);
        $this->assertSame('N/A', $product->taille);
    }

    /*
     * =================================================================
     * Champs miroirs categorie / marque / fournisseur
     * =================================================================
     */

    public function test_categorie_marque_fournisseur_se_resynchronisent_quand_la_relation_change(): void
    {
        $brandA = Brand::create(['name' => 'Nike', 'slug' => 'nike']);
        $brandB = Brand::create(['name' => 'Adidas', 'slug' => 'adidas']);
        $categoryA = Category::create(['name' => 'Football', 'slug' => 'football']);
        $categoryB = Category::create(['name' => 'Basketball', 'slug' => 'basketball']);
        $supplierA = Supplier::create(['name' => 'AliExpress']);
        $supplierB = Supplier::create(['name' => 'CJ Dropshipping']);

        $product = Product::create([
            'reference' => 'REF-SYNC',
            'nom' => 'Maillot Test',
            'type' => 'Player Version',
            'brand_id' => $brandA->id,
            'category_id' => $categoryA->id,
            'supplier_id' => $supplierA->id,
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);

        $this->assertSame('Football', $product->categorie);
        $this->assertSame('Nike', $product->marque);
        $this->assertSame('AliExpress', $product->fournisseur);

        // On change la marque, la catégorie et le fournisseur du produit.
        $product->update([
            'brand_id' => $brandB->id,
            'category_id' => $categoryB->id,
            'supplier_id' => $supplierB->id,
        ]);

        $product->refresh();

        // Les champs miroirs doivent refléter la NOUVELLE relation,
        // pas rester figés sur les anciennes valeurs.
        $this->assertSame('Basketball', $product->categorie);
        $this->assertSame('Adidas', $product->marque);
        $this->assertSame('CJ Dropshipping', $product->fournisseur);
    }

    public function test_categorie_conserve_la_valeur_legacy_si_aucune_relation_nest_definie(): void
    {
        $product = $this->makeProduct(['categorie' => 'Ancienne Catégorie Texte']);

        // Une autre sauvegarde (sans jamais définir category_id) ne doit
        // pas écraser la valeur legacy par 'N/A'.
        $product->update(['nom' => 'Nom mis à jour']);

        $product->refresh();

        $this->assertNull($product->category_id);
        $this->assertSame('Ancienne Catégorie Texte', $product->categorie);
    }

    public function test_marque_repasse_a_na_quand_la_marque_est_explicitement_retiree(): void
    {
        $brand = Brand::create(['name' => 'Nike', 'slug' => 'nike']);

        $product = $this->makeProduct(['brand_id' => $brand->id]);
        $this->assertSame('Nike', $product->marque);

        $product->update(['brand_id' => null]);
        $product->refresh();

        $this->assertSame('N/A', $product->marque);
    }

    public function test_fournisseur_repasse_a_na_quand_le_fournisseur_est_supprime(): void
    {
        $supplier = Supplier::create(['name' => 'AliExpress']);

        $product = $this->makeProduct(['supplier_id' => $supplier->id]);
        $this->assertSame('AliExpress', $product->fournisseur);

        // Suppression du Supplier lui-même (pas une mise à jour du
        // produit) : supplier_id passe à NULL via la contrainte FK
        // nullOnDelete, sans jamais passer par Product::saving().
        $supplier->delete();

        $product->refresh();

        $this->assertNull($product->supplier_id);
        $this->assertSame('N/A', $product->fournisseur);
    }

    public function test_supprimer_un_fournisseur_ne_touche_pas_le_miroir_des_produits_dun_autre_fournisseur(): void
    {
        $supplierA = Supplier::create(['name' => 'AliExpress']);
        $supplierB = Supplier::create(['name' => 'CJ Dropshipping']);

        $productA = $this->makeProduct(['supplier_id' => $supplierA->id]);
        $productB = $this->makeProduct(['supplier_id' => $supplierB->id]);

        $supplierA->delete();

        $productA->refresh();
        $productB->refresh();

        $this->assertSame('N/A', $productA->fournisseur);
        $this->assertSame('CJ Dropshipping', $productB->fournisseur);
        $this->assertSame($supplierB->id, $productB->supplier_id);
    }

    public function test_marque_repasse_a_na_quand_la_marque_est_supprimee(): void
    {
        $brand = Brand::create(['name' => 'Nike', 'slug' => 'nike']);

        $product = $this->makeProduct(['brand_id' => $brand->id]);
        $this->assertSame('Nike', $product->marque);

        // Suppression de la Brand elle-même (pas une mise à jour du
        // produit) : brand_id passe à NULL via la contrainte FK
        // nullOnDelete, sans jamais passer par Product::saving().
        $brand->delete();

        $product->refresh();

        $this->assertNull($product->brand_id);
        $this->assertSame('N/A', $product->marque);
    }

    public function test_supprimer_une_marque_ne_touche_pas_le_miroir_des_produits_dune_autre_marque(): void
    {
        $brandA = Brand::create(['name' => 'Nike', 'slug' => 'nike']);
        $brandB = Brand::create(['name' => 'Adidas', 'slug' => 'adidas']);

        $productA = $this->makeProduct(['brand_id' => $brandA->id]);
        $productB = $this->makeProduct(['brand_id' => $brandB->id]);

        $brandA->delete();

        $productA->refresh();
        $productB->refresh();

        $this->assertSame('N/A', $productA->marque);
        $this->assertSame('Adidas', $productB->marque);
        $this->assertSame($brandB->id, $productB->brand_id);
    }

    public function test_categorie_repasse_a_na_quand_la_categorie_est_supprimee(): void
    {
        $category = Category::create(['name' => 'Football', 'slug' => 'football']);

        $product = $this->makeProduct(['category_id' => $category->id]);
        $this->assertSame('Football', $product->categorie);

        // Suppression de la Category elle-même (pas une mise à jour du
        // produit) : category_id passe à NULL via la contrainte FK
        // nullOnDelete, sans jamais passer par Product::saving().
        $category->delete();

        $product->refresh();

        $this->assertNull($product->category_id);
        $this->assertSame('N/A', $product->categorie);
    }

    public function test_supprimer_une_categorie_ne_touche_pas_le_miroir_des_produits_dune_autre_categorie(): void
    {
        $categoryA = Category::create(['name' => 'Football', 'slug' => 'football']);
        $categoryB = Category::create(['name' => 'Basketball', 'slug' => 'basketball']);

        $productA = $this->makeProduct(['category_id' => $categoryA->id]);
        $productB = $this->makeProduct(['category_id' => $categoryB->id]);

        $categoryA->delete();

        $productA->refresh();
        $productB->refresh();

        $this->assertSame('N/A', $productA->categorie);
        $this->assertSame('Basketball', $productB->categorie);
        $this->assertSame($categoryB->id, $productB->category_id);
    }

    public function test_un_achat_sur_variante_met_a_jour_le_stock_de_la_variante_et_du_produit(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $variant->refresh();
        $product->refresh();

        $this->assertSame(15, $variant->stock);
        $this->assertSame(15, $product->stock);
    }

    public function test_le_stock_produit_reste_synchronise_quand_une_variante_est_editee_directement(): void
    {
        $product = $this->makeProduct();
        $variantA = $this->makeVariant($product, ['sku' => 'SKU-A', 'stock' => 3]);
        $variantB = $this->makeVariant($product, ['sku' => 'SKU-B', 'stock' => 4]);

        // Édition directe (hors StockMovement), comme via le Repeater du formulaire Produit.
        $variantA->update(['stock' => 10]);

        $product->refresh();

        $this->assertSame(14, $product->stock); // 10 + 4
    }

    /** 2.6.11 — réaffectation et agrégats, sans transfert d'historique. */
    public static function parentStockContexts(): array
    {
        $cases = [];
        foreach ([false, true] as $reload) {
            foreach ([false, true] as $loaded) {
                foreach ([3, 5] as $nextStock) {
                    foreach ([0, 4] as $remainingStock) {
                        foreach ([0, 7] as $destinationStock) {
                            $name = implode('-', [(int) $reload, (int) $loaded, $nextStock, $remainingStock, $destinationStock]);
                            $cases[$name] = [$reload, $loaded, $nextStock, $remainingStock, $destinationStock];
                        }
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('parentStockContexts')]
    public function test_reaffecter_une_variante_recalcule_uniquement_les_deux_parents(
        bool $reload,
        bool $loaded,
        int $nextStock,
        int $remainingStock,
        int $destinationStock,
    ): void {
        $oldParent = $this->makeProduct(['activity' => 'sport']);
        $newParent = $this->makeProduct(['activity' => 'sport']);
        $thirdParent = $this->makeProduct(['activity' => 'sport']);
        $variant = $this->makeVariant($oldParent, ['sku' => 'SKU-REPARENT', 'stock' => 3]);
        if ($remainingStock > 0) {
            $this->makeVariant($oldParent, ['sku' => 'SKU-REMAINING', 'stock' => $remainingStock]);
        }
        if ($destinationStock > 0) {
            $this->makeVariant($newParent, ['sku' => 'SKU-DESTINATION', 'stock' => $destinationStock]);
        }
        $this->makeVariant($thirdParent, ['sku' => 'SKU-THIRD', 'stock' => 11]);
        $thirdBefore = $thirdParent->fresh()->getAttributes();
        $this->assertSame(3 + $remainingStock, (int) $oldParent->fresh()->stock);
        $this->assertSame($destinationStock, (int) $newParent->fresh()->stock);

        if ($reload) {
            $variant = $variant->fresh();
        }
        $this->assertSame(! $reload, $variant->wasRecentlyCreated);
        if ($loaded) {
            $variant->load('product');
        }
        $this->assertSame($loaded, $variant->relationLoaded('product'));
        $variant->fill(['product_id' => $newParent->id, 'stock' => $nextStock]);
        if ($loaded) {
            $this->assertSame($oldParent->id, $variant->product->id);
        }

        $this->travel(2)->seconds();
        $queries = $this->captureParentStockQueries(function () use ($variant): void {
            $this->assertTrue($variant->save());
        });
        $this->assertParentStockQueries($queries, [$oldParent->id, $newParent->id]);
        $persisted = $variant->fresh();
        $this->assertSame($newParent->id, (int) $persisted->product_id);
        $this->assertSame($nextStock, $persisted->stock);
        $this->assertSame('SKU-REPARENT', $persisted->sku);
        $this->assertSame($remainingStock, (int) $oldParent->fresh()->stock);
        $this->assertSame($destinationStock + $nextStock, (int) $newParent->fresh()->stock);
        $this->assertSame($thirdBefore, $thirdParent->fresh()->getAttributes());

        // Même instance : wasChanged/wasRecentlyCreated peuvent rester vrais.
        $oldAfterMove = $oldParent->fresh()->getAttributes();
        $this->travel(2)->seconds();
        $queries = $this->captureParentStockQueries(function () use ($variant): void {
            $this->assertTrue($variant->save());
        });
        $this->assertParentStockQueries($queries, ! $reload || $nextStock !== 3 ? [$newParent->id] : []);
        $this->assertSame($oldAfterMove, $oldParent->fresh()->getAttributes());
        $this->assertSame($destinationStock + $nextStock, (int) $newParent->fresh()->stock);
        $this->assertSame($thirdBefore, $thirdParent->fresh()->getAttributes());
    }

    public function test_reaffectations_successives_utilisent_le_parent_de_la_derniere_sauvegarde(): void
    {
        $parentA = $this->makeProduct(['activity' => 'sport']);
        $parentB = $this->makeProduct(['activity' => 'sport']);
        $parentC = $this->makeProduct(['activity' => 'sport']);
        $variant = $this->makeVariant($parentA, ['sku' => 'SKU-SUCCESSIVE', 'stock' => 3]);
        $this->makeVariant($parentB, ['stock' => 7]);
        $this->makeVariant($parentC, ['stock' => 11]);
        $variant->load('product');

        $queries = $this->captureParentStockQueries(function () use ($variant, $parentB): void {
            $this->assertTrue($variant->update(['product_id' => $parentB->id]));
        });
        $this->assertParentStockQueries($queries, [$parentA->id, $parentB->id]);
        $this->assertSame(0, (int) $parentA->fresh()->stock);
        $this->assertSame(10, (int) $parentB->fresh()->stock);
        $parentABeforeSecondMove = $parentA->fresh()->getAttributes();

        $this->travel(2)->seconds();
        $queries = $this->captureParentStockQueries(function () use ($variant, $parentC): void {
            $this->assertTrue($variant->update(['product_id' => $parentC->id]));
        });
        $this->assertParentStockQueries($queries, [$parentB->id, $parentC->id]);
        $this->assertSame($parentABeforeSecondMove, $parentA->fresh()->getAttributes());
        $this->assertSame(7, (int) $parentB->fresh()->stock);
        $this->assertSame(14, (int) $parentC->fresh()->stock);
        $this->assertSame($parentC->id, (int) $variant->fresh()->product_id);
        $this->assertSame('SKU-SUCCESSIVE', $variant->fresh()->sku);
    }

    public function test_creation_modification_et_suppression_conservent_le_recalcul_du_parent(): void
    {
        $product = $this->makeProduct(['activity' => 'sport']);
        $remaining = $this->makeVariant($product, ['stock' => 4]);
        $variant = null;
        $queries = $this->captureParentStockQueries(function () use ($product, &$variant): void {
            $variant = $this->makeVariant($product, ['stock' => 3]);
        });
        $this->assertParentStockQueries($queries, [$product->id]);
        $this->assertSame(7, (int) $product->fresh()->stock);

        $queries = $this->captureParentStockQueries(function () use ($variant): void {
            $this->assertTrue($variant->update(['stock' => 5]));
        });
        $this->assertParentStockQueries($queries, [$product->id]);
        $this->assertSame(9, (int) $product->fresh()->stock);

        foreach ([$variant, $remaining] as $deleted) {
            $queries = $this->captureParentStockQueries(function () use ($deleted): void {
                $this->assertTrue($deleted->delete());
            });
            $this->assertParentStockQueries($queries, [$product->id]);
            $this->assertDatabaseMissing('product_variants', ['id' => $deleted->id]);
            $this->assertSame($deleted === $variant ? 4 : 0, (int) $product->fresh()->stock);
        }
    }

    public function test_echec_sql_de_reaffectation_ne_recalcule_aucun_parent(): void
    {
        $oldParent = $this->makeProduct(['activity' => 'sport']);
        $newParent = $this->makeProduct(['activity' => 'sport']);
        $variant = $this->makeVariant($oldParent, ['sku' => 'SKU-BEFORE-FAILURE', 'stock' => 3])->fresh();
        $this->makeVariant($newParent, ['sku' => 'SKU-ALREADY-USED', 'stock' => 7]);
        $oldBefore = $oldParent->fresh()->getAttributes();
        $newBefore = $newParent->fresh()->getAttributes();
        $variantBefore = $variant->getAttributes();
        $failure = null;

        $this->travel(2)->seconds();
        $queries = $this->captureParentStockQueries(function () use ($variant, $newParent, &$failure): void {
            try {
                $variant->update(['product_id' => $newParent->id, 'stock' => 5, 'sku' => 'SKU-ALREADY-USED']);
            } catch (QueryException $exception) {
                $failure = $exception;
            }
        });

        $this->assertInstanceOf(QueryException::class, $failure);
        $this->assertParentStockQueries($queries, []);
        $this->assertSame($variantBefore, $variant->fresh()->getAttributes());
        $this->assertSame($oldBefore, $oldParent->fresh()->getAttributes());
        $this->assertSame($newBefore, $newParent->fresh()->getAttributes());
    }

    /** Capture locale, sans listener global ni comptage des requêtes miroir. */
    private function captureParentStockQueries(callable $operation): array
    {
        $connection = (new ProductVariant)->getConnection();
        $wasLogging = $connection->logging();
        $offset = count($connection->getQueryLog());
        $connection->enableQueryLog();
        try {
            $operation();

            return array_slice($connection->getQueryLog(), $offset);
        } finally {
            if (! $wasLogging) {
                $connection->disableQueryLog();
                $connection->flushQueryLog();
            }
        }
    }

    private function assertParentStockQueries(array $queries, array $expectedParentIds): void
    {
        $sumParents = [];
        $updatedParents = [];
        foreach ($queries as $query) {
            $sql = strtolower(str_replace(['"', '`', '[', ']'], '', $query['query']));
            if (str_starts_with($sql, 'select sum(stock) as aggregate from product_variants ')) {
                $sumParents[] = (int) $query['bindings'][0];
            }
            if (str_starts_with($sql, 'update products set ')) {
                $updatedParents[] = (int) $query['bindings'][array_key_last($query['bindings'])];
            }
        }
        sort($expectedParentIds);
        sort($sumParents);
        sort($updatedParents);
        $this->assertSame($expectedParentIds, $sumParents, 'Une seule somme par parent concerné.');
        $this->assertSame($expectedParentIds, $updatedParents, 'Une seule mise à jour par parent concerné.');
    }

    public function test_une_vente_en_stock_insuffisant_est_rejetee_sans_ecriture_partielle(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 2]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => 'sale',
                'quantity' => 5,
            ]);
        } finally {
            $variant->refresh();
            $product->refresh();

            // Aucune écriture partielle : stock inchangé (2, synchronisé dès la création de la variante).
            $this->assertSame(2, $variant->stock);
            $this->assertSame(2, $product->stock);

            // Aucune ligne "fantôme" créée dans stock_movements.
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_un_ajustement_peut_legalement_ramener_le_stock_a_zero(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 8]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'adjustment',
            'quantity' => 0,
        ]);

        $variant->refresh();
        $product->refresh();

        $this->assertSame(0, $variant->stock);
        $this->assertSame(0, $product->stock);
    }

    public function test_un_mouvement_de_type_transfert_est_rejete_sans_ecriture(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => 'transfer',
                'quantity' => 1,
            ]);
        } finally {
            $variant->refresh();

            $this->assertSame(5, $variant->stock);
            $this->assertSame(0, StockMovement::count());
        }
    }

    public function test_les_champs_json_du_produit_sont_bien_castes_en_tableau(): void
    {
        $product = $this->makeProduct([
            'photos' => ['photo1.jpg', 'photo2.jpg'],
            'marketplaces' => ['amazon', 'ebay'],
            'featured' => true,
            'status' => true,
        ]);

        $product->refresh();

        $this->assertIsArray($product->photos);
        $this->assertSame(['photo1.jpg', 'photo2.jpg'], $product->photos);
        $this->assertIsArray($product->marketplaces);
        $this->assertSame(['amazon', 'ebay'], $product->marketplaces);
        $this->assertIsBool($product->featured);
        $this->assertTrue($product->featured);
        $this->assertIsBool($product->status);
        $this->assertTrue($product->status);
    }

    /*
     * =================================================================
     * D.1 — product_variant_id réellement optionnel
     * =================================================================
     */

    public function test_un_mouvement_peut_etre_cree_sur_un_produit_sans_aucune_variante(): void
    {
        $product = $this->makeProduct(['stock' => 5]);

        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $product->refresh();

        $this->assertSame(15, $product->stock);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_un_produit_avec_variantes_refuse_un_mouvement_sans_variante_selectionnee(): void
    {
        $product = $this->makeProduct();
        $this->makeVariant($product, ['stock' => 5]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'purchase',
                'quantity' => 10,
            ]);
        } finally {
            $product->refresh();

            // Aucune écriture partielle : le stock (déjà synchronisé
            // à 5 par la variante) ne doit pas bouger.
            $this->assertSame(5, $product->stock);
            $this->assertSame(0, StockMovement::count());
        }
    }

    /*
     * =================================================================
     * D.2 — Modification d'un mouvement existant
     * =================================================================
     */

    public function test_modifier_la_quantite_dun_mouvement_recalcule_le_stock(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $variant->refresh();
        $this->assertSame(15, $variant->stock);

        $movement->update(['quantity' => 20]);

        $movement->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(5, $movement->stock_before);
        $this->assertSame(25, $movement->stock_after);
        $this->assertSame(25, $variant->stock);
        $this->assertSame(25, $product->stock);
    }

    public function test_modifier_un_mouvement_recalcule_en_cascade_les_mouvements_suivants(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $vente = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'sale',
            'quantity' => 4,
        ]);

        $variant->refresh();
        $this->assertSame(6, $variant->stock); // 10 - 4

        // On corrige l'achat initial : 10 -> 20.
        $achat->update(['quantity' => 20]);

        $achat->refresh();
        $vente->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(0, $achat->stock_before);
        $this->assertSame(20, $achat->stock_after);

        // La vente qui suit doit être recalculée en cascade.
        $this->assertSame(20, $vente->stock_before);
        $this->assertSame(16, $vente->stock_after); // 20 - 4

        $this->assertSame(16, $variant->stock);
        $this->assertSame(16, $product->stock);
    }

    public function test_modifier_un_mouvement_est_rejete_si_cela_rendrait_le_stock_negatif_plus_loin_dans_lhistorique(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'sale',
            'quantity' => 8,
        ]);

        $variant->refresh();
        $this->assertSame(2, $variant->stock); // 10 - 8

        $this->expectException(\Exception::class);

        try {
            // Si l'achat initial passait de 10 à 5, la vente de 8 qui
            // suit deviendrait impossible (5 - 8 < 0).
            $achat->update(['quantity' => 5]);
        } finally {
            $achat->refresh();
            $variant->refresh();
            $product->refresh();

            // Aucune écriture partielle : tout reste inchangé.
            $this->assertSame(10, $achat->quantity);
            $this->assertSame(2, $variant->stock);
            $this->assertSame(2, $product->stock);
        }
    }

    public function test_on_ne_peut_pas_changer_le_produit_ou_la_variante_dun_mouvement_existant(): void
    {
        $product = $this->makeProduct();
        $variantA = $this->makeVariant($product, ['sku' => 'SKU-A', 'stock' => 5]);
        $variantB = $this->makeVariant($product, ['sku' => 'SKU-B', 'stock' => 5]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variantA->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $this->expectException(\Exception::class);

        try {
            $movement->update(['product_variant_id' => $variantB->id]);
        } finally {
            $movement->refresh();
            $this->assertSame($variantA->id, $movement->product_variant_id);
        }
    }

    public function test_modifier_un_champ_sans_impact_sur_le_stock_ne_recalcule_rien(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $movement->update(['notes' => 'Commentaire ajouté après coup']);

        $movement->refresh();
        $variant->refresh();

        $this->assertSame('Commentaire ajouté après coup', $movement->notes);
        $this->assertSame(5, $movement->stock_before);
        $this->assertSame(15, $movement->stock_after);
        $this->assertSame(15, $variant->stock);
    }

    /*
     * =================================================================
     * D.2 — Suppression d'un mouvement existant
     * =================================================================
     */

    public function test_supprimer_un_mouvement_retablit_correctement_le_stock(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat1 = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $achat2 = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $variant->refresh();
        $this->assertSame(15, $variant->stock);

        $achat1->delete();

        $achat2->refresh();
        $variant->refresh();
        $product->refresh();

        $this->assertSame(1, StockMovement::count());
        $this->assertSame(0, $achat2->stock_before);
        $this->assertSame(5, $achat2->stock_after);
        $this->assertSame(5, $variant->stock);
        $this->assertSame(5, $product->stock);
    }

    public function test_supprimer_un_mouvement_est_rejete_si_cela_rendrait_le_stock_negatif(): void
    {
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'sale',
            'quantity' => 8,
        ]);

        $variant->refresh();
        $this->assertSame(2, $variant->stock);

        $this->expectException(\Exception::class);

        try {
            $achat->delete();
        } finally {
            $variant->refresh();
            $product->refresh();

            $this->assertSame(2, StockMovement::count());
            $this->assertSame(2, $variant->stock);
            $this->assertSame(2, $product->stock);
        }
    }

    public function test_supprimer_lunique_mouvement_dun_produit_sans_variante_retablit_le_stock_initial(): void
    {
        $product = $this->makeProduct(['stock' => 3]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 7,
        ]);

        $product->refresh();
        $this->assertSame(10, $product->stock);

        $movement->delete();

        $product->refresh();
        $this->assertSame(3, $product->stock);
        $this->assertSame(0, StockMovement::count());
    }

    /*
     * =================================================================
     * user_id renseigné automatiquement à la création
     * =================================================================
     */

    public function test_le_user_id_est_renseigne_automatiquement_avec_lutilisateur_authentifie(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $this->actingAs($user);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $this->assertSame($user->id, $movement->user_id);
    }

    public function test_le_user_id_reste_null_sans_utilisateur_authentifie(): void
    {
        // Aucun `actingAs()` : simule un contexte système/CLI/job sans
        // utilisateur connecté. La création ne doit pas être bloquée.
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $this->assertNull($movement->user_id);
        $variant->refresh();
        $this->assertSame(15, $variant->stock);
    }

    public function test_un_user_id_fourni_explicitement_nest_pas_ecrase(): void
    {
        $loggedInUser = User::factory()->create();
        $attributedTo = User::factory()->create();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $this->actingAs($loggedInUser);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
            'user_id' => $attributedTo->id,
        ]);

        $this->assertSame($attributedTo->id, $movement->user_id);
    }

    public function test_un_mouvement_expose_une_relation_vers_lutilisateur_qui_la_cree(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 5]);

        $this->actingAs($user);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 10,
        ]);

        $this->assertInstanceOf(User::class, $movement->user);
        $this->assertSame($user->id, $movement->user->id);
    }
}
