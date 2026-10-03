<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Étape T11b — StockMovement est désormais le point d'écriture qui
 * tient warehouse_stocks à jour (dimension entrepôt), en plus du
 * stock global Product/ProductVariant déjà couvert par
 * StockMovementTest.php. Contrairement à ce dernier, ce fichier ne
 * crée PAS d'entrepôt par défaut dans un setUp() commun : certains
 * tests (absence d'entrepôt par défaut) ont justement besoin de son
 * absence.
 */
class StockMovementWarehouseTest extends TestCase
{
    use RefreshDatabase;

    public static function newWarehousePurchaseContexts(): array
    {
        return [
            'produit stock initial nul' => [false, 0],
            'produit stock initial non nul' => [false, 7],
            'variante stock initial nul' => [true, 0],
            'variante stock initial non nul' => [true, 7],
        ];
    }

    #[DataProvider('newWarehousePurchaseContexts')]
    public function test_nouvel_entrepot_ne_duplique_pas_le_stock_deja_reparti(bool $withVariant, int $baseline): void
    {
        $a = $this->makeDefaultWarehouse();
        $b = Warehouse::create(['name' => 'B', 'code' => 'b']);
        $c = Warehouse::create(['name' => 'C', 'code' => 'c']);
        $product = $this->makeProduct(['stock' => $baseline]);
        $variant = $withVariant ? $this->makeVariant($product, ['stock' => $baseline]) : null;
        $key = ['product_id' => $product->id, 'product_variant_id' => $variant?->id];
        $sibling = $withVariant ? $this->makeVariant($product, ['stock' => 4]) : null;
        if ($sibling) {
            WarehouseStock::create(['warehouse_id' => $b->id, 'product_id' => $product->id, 'product_variant_id' => $sibling->id, 'stock' => 4]);
        }
        $other = $this->makeProduct(['stock' => 11]);
        $otherLine = WarehouseStock::create(['warehouse_id' => $a->id, 'product_id' => $other->id, 'stock' => 11]);
        $otherBefore = (array) DB::table('warehouse_stocks')->where('id', $otherLine->id)->sole();
        $first = StockMovement::create($key + ['warehouse_id' => $a->id, 'type' => 'purchase', 'quantity' => 10]);
        $this->assertSame($baseline, $first->fresh()->stock_before);
        $this->assertSame($baseline + 10, $first->fresh()->stock_after);
        $this->assertSame($baseline + 10, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $a->id)->value('stock'));
        $aBefore = (array) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $a->id)->sole();
        $this->assertDatabaseMissing('warehouse_stocks', $key + ['warehouse_id' => $b->id]);
        $this->travel(2)->seconds();
        try {
            $second = StockMovement::create($key + ['warehouse_id' => $b->id, 'type' => 'purchase', 'quantity' => 5]);
            $this->assertSame($baseline + 10, $second->fresh()->stock_before);
            $this->assertSame($baseline + 15, $second->fresh()->stock_after);
            $this->assertSame(5, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $b->id)->value('stock'));
            $this->assertSame($baseline + 15, (int) ($variant ?? $product)->fresh()->stock);
            $this->assertSame($baseline + 15 + ($sibling ? 4 : 0), (int) $product->fresh()->stock);
            $this->assertSame($baseline + 15, (int) DB::table('warehouse_stocks')->where($key)->sum('stock'));
            $bBefore = (array) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $b->id)->sole();
            $this->assertDatabaseMissing('warehouse_stocks', $key + ['warehouse_id' => $c->id]);
            StockMovement::create($key + ['warehouse_id' => $c->id, 'type' => 'purchase', 'quantity' => 3]);
            $this->assertSame(3, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $c->id)->value('stock'));
            $this->assertSame($baseline + 18, (int) ($variant ?? $product)->fresh()->stock);
            $this->assertSame($baseline + 18 + ($sibling ? 4 : 0), (int) $product->fresh()->stock);
            $this->assertSame((int) ($variant ?? $product)->fresh()->stock, (int) DB::table('warehouse_stocks')->where($key)->sum('stock'));
            $this->assertSame((int) $product->fresh()->stock, (int) DB::table('warehouse_stocks')->where('product_id', $product->id)->sum('stock'));
            $this->assertSame($aBefore, (array) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $a->id)->sole());
            $this->assertSame($bBefore, (array) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $b->id)->sole());
            $this->assertSame($otherBefore, (array) DB::table('warehouse_stocks')->where('id', $otherLine->id)->sole());
            if ($sibling) {
                $this->assertSame(4, (int) $sibling->fresh()->stock);
                $this->assertSame(4, (int) DB::table('warehouse_stocks')->where('product_variant_id', $sibling->id)->value('stock'));
            }
            // L'ancrage initial reste celui du premier mouvement après le rejeu UPDATE.
            $this->assertTrue($first->fresh()->update(['quantity' => 12]));
            $this->assertSame($baseline, $first->fresh()->stock_before);
            $this->assertSame($baseline + 12, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $a->id)->value('stock'));
            $this->assertSame(5, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $b->id)->value('stock'));
            $this->assertSame(3, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $c->id)->value('stock'));
            $this->assertSame($baseline + 20, (int) ($variant ?? $product)->fresh()->stock);
            $this->assertSame($baseline + 20 + ($sibling ? 4 : 0), (int) $product->fresh()->stock);
            $this->assertSame($baseline + 20, (int) DB::table('warehouse_stocks')->where($key)->sum('stock'));
        } finally {
            $this->travelBack();
        }
    }

    public static function firstWarehouseSaleContexts(): array
    {
        return [
            'produit sans ligne' => [false, false],
            'produit ligne vide ailleurs' => [false, true],
            'variante sans ligne' => [true, false],
            'variante ligne vide ailleurs' => [true, true],
        ];
    }

    #[DataProvider('firstWarehouseSaleContexts')]
    public function test_premier_rattachement_conserve_le_stock_initial_non_reparti(bool $withVariant, bool $emptyRow): void
    {
        $a = $this->makeDefaultWarehouse();
        $b = Warehouse::create(['name' => 'B', 'code' => 'b']);
        $product = $this->makeProduct(['stock' => 7]);
        $variant = $withVariant ? $this->makeVariant($product, ['stock' => 7]) : null;
        $key = ['product_id' => $product->id, 'product_variant_id' => $variant?->id];
        if ($emptyRow) {
            WarehouseStock::create($key + ['warehouse_id' => $b->id, 'stock' => 0]);
        }
        $movement = StockMovement::create($key + ['warehouse_id' => $a->id, 'type' => 'sale', 'quantity' => 2]);
        $this->assertSame(7, $movement->fresh()->stock_before);
        $this->assertSame(5, $movement->fresh()->stock_after);
        $this->assertSame(5, (int) ($variant ?? $product)->fresh()->stock);
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame(5, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $a->id)->value('stock'));
        $this->assertSame(5, (int) DB::table('warehouse_stocks')->where($key)->sum('stock'));
        if ($emptyRow) {
            $this->assertSame(0, (int) DB::table('warehouse_stocks')->where($key)->where('warehouse_id', $b->id)->value('stock'));
        }
    }

    public static function emptyWarehouseSaleContexts(): array
    {
        return [
            'produit appel direct' => [false, false],
            'produit transaction englobante' => [false, true],
            'variante appel direct' => [true, false],
            'variante transaction englobante' => [true, true],
        ];
    }

    #[DataProvider('emptyWarehouseSaleContexts')]
    public function test_vente_nouvel_entrepot_vide_refusee_sans_ecriture_residuelle(bool $withVariant, bool $outerTransaction): void
    {
        $a = $this->makeDefaultWarehouse();
        $b = Warehouse::create(['name' => 'B', 'code' => 'b']);
        $product = $this->makeProduct();
        $variant = $withVariant ? $this->makeVariant($product) : null;
        $key = ['product_id' => $product->id, 'product_variant_id' => $variant?->id];
        StockMovement::create($key + ['warehouse_id' => $a->id, 'type' => 'purchase', 'quantity' => 10]);
        $snapshot = static function (): array {
            $rows = [];
            foreach (['products', 'product_variants', 'stock_movements', 'warehouse_stocks', 'product_attribute_values', 'product_variant_attribute_values'] as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
            }

            return $rows;
        };
        $before = $snapshot();
        $connection = DB::connection();
        $initialLevel = $connection->transactionLevel();
        $this->travel(2)->seconds();
        try {
            if ($outerTransaction) {
                $connection->beginTransaction();
            }
            $operationLevel = $connection->transactionLevel();
            $caught = null;
            try {
                StockMovement::create($key + ['warehouse_id' => $b->id, 'type' => 'sale', 'quantity' => 1]);
            } catch (\Exception $exception) {
                $caught = $exception;
            }
            $this->assertNotNull($caught, 'Le stock global en A ne permet pas une vente dans B vide.');
            $this->assertSame('Stock insuffisant pour effectuer cette vente.', $caught->getMessage());
            $this->assertSame($operationLevel, $connection->transactionLevel());
            $this->assertSame($before, $snapshot(), 'Lectures DB fraîches, toutes les colonnes et timestamps compris.');
            $this->assertDatabaseMissing('warehouse_stocks', $key + ['warehouse_id' => $b->id]);
            $this->assertSame(10, (int) ($variant ?? $product)->fresh()->stock);
            $this->assertSame(10, (int) $product->fresh()->stock);
        } finally {
            $connection->rollBack($initialLevel);
            $this->travelBack();
        }
        $this->assertSame($initialLevel, $connection->transactionLevel());
    }

    public static function deletionReplayContexts(): array
    {
        $cases = [];
        foreach ([false, true] as $variant) {
            foreach ([0, 7] as $baseline) {
                foreach (['last_b', 'anchor_a', 'legacy_b', 'all'] as $operation) {
                    $cases[$operation.' variant='.(int) $variant.' baseline='.$baseline] = [$variant, $baseline, $operation];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('deletionReplayContexts')]
    public function test_rejeu_suppression_recalcule_entrepots_avant_exclusion_et_preserve_ancrage(
        bool $withVariant, int $baseline, string $operation,
    ): void {
        $a = $this->makeDefaultWarehouse();
        $b = Warehouse::create(['name' => 'B', 'code' => 'b']);
        $c = Warehouse::create(['name' => 'C', 'code' => 'c']);
        $product = $this->makeProduct(['stock' => $baseline]);
        $variant = $withVariant ? $this->makeVariant($product, ['stock' => $baseline]) : null;
        $key = ['product_id' => $product->id, 'product_variant_id' => $variant?->id];
        $lineA = WarehouseStock::create($key + ['warehouse_id' => $a->id, 'stock' => $baseline]);
        $lineB = WarehouseStock::create($key + ['warehouse_id' => $b->id, 'stock' => 0]);
        $lineC = WarehouseStock::create($key + ['warehouse_id' => $c->id, 'stock' => 0]);
        $untouched = $this->makeProduct(['stock' => 11]);
        $otherLine = WarehouseStock::create(['warehouse_id' => $b->id, 'product_id' => $untouched->id, 'stock' => 11]);
        $sibling = $withVariant ? $this->makeVariant($product, ['stock' => 4]) : null;
        $siblingLine = $sibling ? WarehouseStock::create([
            'warehouse_id' => $b->id, 'product_id' => $product->id, 'product_variant_id' => $sibling->id, 'stock' => 4,
        ]) : null;
        $first = StockMovement::create($key + ['warehouse_id' => $a->id, 'type' => 'purchase', 'quantity' => 5]);
        $second = StockMovement::create($key + ['warehouse_id' => $b->id, 'type' => 'purchase', 'quantity' => 3]);
        $this->assertSame($baseline, $first->fresh()->stock_before);
        $this->assertSame($baseline + 8, (int) ($variant ?? $product)->fresh()->stock);
        $this->assertSame($baseline + 5, $lineA->fresh()->stock);
        $this->assertSame(3, $lineB->fresh()->stock);
        if ($operation === 'legacy_b') {
            // Fixture historique sans warehouse_id ; le repli existant doit cibler A, pas B.
            DB::table('stock_movements')->where('id', $second->id)->update(['warehouse_id' => null]);
            DB::table('warehouse_stocks')->where('id', $lineA->id)->update(['stock' => $baseline + 8]);
            DB::table('warehouse_stocks')->where('id', $lineB->id)->update(['stock' => 0]);
            $second->refresh();
        }
        $unchanged = function () use ($lineC, $otherLine, $sibling, $siblingLine, $untouched): array {
            return [
                (array) DB::table('warehouse_stocks')->where('id', $lineC->id)->sole(),
                (array) DB::table('warehouse_stocks')->where('id', $otherLine->id)->sole(),
                (array) DB::table('products')->where('id', $untouched->id)->sole(),
                $sibling ? (array) DB::table('product_variants')->where('id', $sibling->id)->sole() : null,
                $siblingLine ? (array) DB::table('warehouse_stocks')->where('id', $siblingLine->id)->sole() : null,
            ];
        };
        $before = $unchanged();
        $warehouseIds = DB::table('warehouse_stocks')->orderBy('id')->pluck('id')->all();
        $initialLevel = DB::connection()->transactionLevel();
        $this->travel(2)->seconds();
        try {
            $deleted = $operation === 'anchor_a' ? $first : $second;
            $this->assertTrue($deleted->delete());
            $this->assertDatabaseMissing('stock_movements', ['id' => $deleted->id]);
            $expectedA = $operation === 'anchor_a' ? $baseline : $baseline + 5;
            $expectedB = $operation === 'anchor_a' ? 3 : 0;
            $this->assertSame($expectedA, $lineA->fresh()->stock);
            $this->assertSame($expectedB, $lineB->fresh()->stock);
            $expectedTarget = $expectedA + $expectedB;
            $this->assertSame($expectedTarget, (int) ($variant ?? $product)->fresh()->stock);
            $this->assertSame($expectedTarget + ($sibling ? 4 : 0), (int) $product->fresh()->stock);
            $this->assertSame($expectedTarget, (int) DB::table('warehouse_stocks')->where($key)->sum('stock'));
            $this->assertSame((int) $product->fresh()->stock, (int) DB::table('warehouse_stocks')->where('product_id', $product->id)->sum('stock'));
            $remaining = ($operation === 'anchor_a' ? $second : $first)->fresh();
            $this->assertSame($baseline, $remaining->stock_before);
            $this->assertSame($expectedTarget, $remaining->stock_after);
            if ($operation === 'all') {
                $this->assertTrue($first->delete());
                $this->assertSame($baseline, $lineA->fresh()->stock);
                $this->assertSame(0, $lineB->fresh()->stock);
                $this->assertSame($baseline, (int) ($variant ?? $product)->fresh()->stock);
                $this->assertSame($baseline + ($sibling ? 4 : 0), (int) $product->fresh()->stock);
                $this->assertSame($baseline, (int) DB::table('warehouse_stocks')->where($key)->sum('stock'));
                $this->assertSame(0, StockMovement::where($key)->count());
            }
            $this->assertSame($before, $unchanged(), 'Données et timestamps des entrepôts/cibles non concernés inchangés.');
            $this->assertSame($warehouseIds, DB::table('warehouse_stocks')->orderBy('id')->pluck('id')->all());
            $this->assertSame($initialLevel, DB::connection()->transactionLevel());
        } finally {
            $this->travelBack();
        }
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
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
            'sku' => 'SKU-'.uniqid(),
            'size' => 'M',
            'stock' => 0,
            'status' => 'active',
        ], $attributes));
    }

    private function makeDefaultWarehouse(array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'name' => 'Entrepôt par défaut',
            'code' => 'defaut',
            'is_default' => true,
        ], $attributes));
    }

    /*
     * =================================================================
     * Résolution de l'entrepôt à la création
     * =================================================================
     */

    public function test_un_mouvement_sans_entrepot_explicite_est_rattache_a_lentrepot_par_defaut(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 0]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->assertSame($default->id, $movement->warehouse_id);
    }

    public function test_un_mouvement_avec_entrepot_explicite_conserve_cet_entrepot(): void
    {
        $this->makeDefaultWarehouse();
        $autre = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b']);
        $product = $this->makeProduct(['stock' => 0]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $autre->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->assertSame($autre->id, $movement->warehouse_id);
    }

    public function test_la_creation_est_refusee_si_aucun_entrepot_par_defaut_nexiste(): void
    {
        // Aucun Warehouse créé du tout dans ce test.
        $product = $this->makeProduct(['stock' => 0]);

        $this->expectException(\Exception::class);

        try {
            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'purchase',
                'quantity' => 5,
            ]);
        } finally {
            $this->assertSame(0, StockMovement::count());
            $this->assertSame(0, $product->fresh()->stock);
        }
    }

    public function test_on_ne_peut_pas_changer_lentrepot_dun_mouvement_existant(): void
    {
        $default = $this->makeDefaultWarehouse();
        $autre = Warehouse::create(['name' => 'Entrepôt B', 'code' => 'b']);
        $product = $this->makeProduct(['stock' => 0]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->expectException(\Exception::class);

        try {
            $movement->update(['warehouse_id' => $autre->id]);
        } finally {
            $movement->refresh();
            $this->assertSame($default->id, $movement->warehouse_id);
        }
    }

    /*
     * =================================================================
     * warehouse_stocks tenu à jour par les mouvements
     * =================================================================
     */

    public function test_le_warehouse_stock_est_incremente_apres_un_achat(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 0]);

        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 12,
        ]);

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->first();

        $this->assertNotNull($line);
        $this->assertSame(12, $line->stock);
    }

    public function test_le_warehouse_stock_est_decremente_apres_une_vente(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 10]);

        // Une ligne warehouse_stocks doit d'abord exister (simulée
        // comme si T11a l'avait rétro-remplie).
        WarehouseStock::create([
            'warehouse_id' => $default->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'stock' => 10,
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'sale',
            'quantity' => 4,
        ]);

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertSame(6, $line->stock);
    }

    public function test_le_warehouse_stock_sauto_cree_si_absent_avant_le_mouvement(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 0]);
        $variant = $this->makeVariant($product, ['stock' => 0]);

        // Aucune ligne warehouse_stocks pré-existante pour cette
        // variante : le mouvement doit l'auto-créer (firstOrCreate),
        // pas échouer silencieusement.
        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'type' => 'purchase',
            'quantity' => 3,
        ]);

        $this->assertSame(
            3,
            WarehouseStock::where('warehouse_id', $default->id)
                ->where('product_variant_id', $variant->id)
                ->value('stock')
        );
    }

    public function test_la_somme_des_warehouse_stock_reste_coherente_avec_le_stock_global_apres_plusieurs_mouvements(): void
    {
        $this->makeDefaultWarehouse();
        $product = $this->makeProduct();
        $variantA = $this->makeVariant($product, ['sku' => 'SKU-A', 'stock' => 0]);
        $variantB = $this->makeVariant($product, ['sku' => 'SKU-B', 'stock' => 0]);

        StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variantA->id, 'type' => 'purchase', 'quantity' => 10]);
        StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variantB->id, 'type' => 'purchase', 'quantity' => 6]);
        StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variantA->id, 'type' => 'sale', 'quantity' => 3]);

        $product->refresh();

        $sommeWarehouseStock = WarehouseStock::where('product_id', $product->id)->sum('stock');

        $this->assertSame(13, $product->stock); // (10 - 3) + 6
        $this->assertSame($product->stock, $sommeWarehouseStock);
    }

    public function test_supprimer_un_mouvement_retablit_le_warehouse_stock(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat1 = StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'type' => 'purchase', 'quantity' => 10]);
        StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'type' => 'purchase', 'quantity' => 5]);

        $achat1->delete();

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertSame(5, $line->stock);
        $this->assertSame(5, $variant->fresh()->stock);
    }

    public function test_modifier_la_quantite_dun_mouvement_recalcule_le_warehouse_stock(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product, ['stock' => 0]);

        $achat = StockMovement::create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'type' => 'purchase', 'quantity' => 10]);

        $achat->update(['quantity' => 20]);

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertSame(20, $line->stock);
        $this->assertSame(20, $variant->fresh()->stock);
    }

    /*
     * =================================================================
     * Mouvement historique (warehouse_id NULL) rejoué par resyncLedger
     * =================================================================
     */

    public function test_editer_un_mouvement_historique_sans_entrepot_le_rattache_a_lentrepot_par_defaut(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 10]);

        // Simule un mouvement créé AVANT T11b (warehouse_id NULL),
        // en insérant directement en base pour contourner
        // StockMovement::creating() (qui résoudrait déjà l'entrepôt).
        $movementId = DB::table('stock_movements')->insertGetId([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 10,
            'stock_before' => 0,
            'stock_after' => 10,
            'warehouse_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $movement = StockMovement::find($movementId);
        $this->assertNull($movement->warehouse_id);

        // Une édition déclenche resyncLedger(), qui doit résoudre le
        // repli sur l'entrepôt par défaut pour ce mouvement historique
        // sans planter (warehouse_id = 0 serait rejeté par la FK).
        $movement->update(['quantity' => 15]);

        $product->refresh();
        $this->assertSame(15, $product->stock);

        $line = WarehouseStock::where('warehouse_id', $default->id)
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->first();

        $this->assertNotNull($line);
        $this->assertSame(15, $line->stock);
    }

    /*
     * =================================================================
     * Garde de suppression d'un entrepôt référencé par des mouvements
     * =================================================================
     */

    public function test_un_entrepot_reference_par_des_mouvements_ne_peut_pas_etre_supprime(): void
    {
        $default = $this->makeDefaultWarehouse();
        $product = $this->makeProduct(['stock' => 0]);

        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
        ]);

        $this->expectException(\Exception::class);

        try {
            $default->delete();
        } finally {
            $this->assertDatabaseHas('warehouses', ['id' => $default->id]);
        }
    }
}
