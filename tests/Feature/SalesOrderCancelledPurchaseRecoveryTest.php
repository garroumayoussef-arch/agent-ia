<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesOrderCancelledPurchaseRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_preparation_groupee_garde_un_nombre_constant_de_requetes(): void
    {
        $first = self::scenario();
        $measure = function () use ($first): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $result = SalesOrderItemAllocation::previewCancelledPurchaseRecoveriesFor($first['sale']);
                return [$result, count(DB::getQueryLog())];
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        };
        [$single, $singleCount] = $measure();
        $this->assertCount(1, $single['offers']);
        for ($i = 0; $i < 5; $i++) {
            $other = self::scenario();
            DB::table('sales_order_items')->where('id', $other['item']->id)->update(['sales_order_id' => $first['sale']->id]);
        }
        [$multiple, $multipleCount] = $measure();
        $this->assertCount(6, $multiple['offers']);
        $this->assertSame([], $multiple['reasons']);
        $this->assertSame($singleCount, $multipleCount);
        $this->assertDatabaseCount('sales_order_item_allocations', 6);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public static function previewSourcingCases(): array
    {
        return array_map(fn ($case) => [$case], ['product', 'specific', 'generic', 'excluded_specific', 'inactive', 'tie']);
    }

    #[DataProvider('previewSourcingCases')]
    public function test_preparation_groupee_equivaut_au_resolver_existant(string $case): void
    {
        $product = Product::factory()->create();
        $variant = in_array($case, ['specific', 'generic', 'excluded_specific'], true)
            ? ProductVariant::factory()->create(['product_id' => $product->id]) : null;
        $s = self::scenario($product, $variant);
        if ($case === 'generic') {
            $s['source']->update(['product_variant_id' => null]);
            $s['alternative']->update(['product_variant_id' => null]);
        }
        if (in_array($case, ['excluded_specific', 'inactive'], true)) {
            $s['alternative']->update(['is_active' => false]);
        }
        if ($case === 'excluded_specific') {
            $product->supplierSourcings()->create(['supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id, 'is_active' => true]);
        }
        if ($case === 'tie') {
            $product->supplierSourcings()->create(['supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id, 'is_active' => true, 'priority' => 2]);
        }
        $grouped = SalesOrderItemAllocation::previewCancelledPurchaseRecoveriesFor($s['sale']);
        try {
            $expected = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        } catch (\Exception $e) {
            $this->assertSame([], $grouped['offers']);
            $this->assertSame(['Ligne #'.$s['item']->id.' : '.$e->getMessage()], $grouped['reasons']);
            return;
        }
        $this->assertSame([$s['allocation']->id => $expected], $grouped['offers']);
        $this->assertSame([], $grouped['reasons']);
    }

    /** Fixture partagée par les trois suites D2.18, sans nouveau fichier hors allowlist. */
    public static function scenario(?Product $product = null, ?ProductVariant $variant = null): array
    {
        $product ??= Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $source = $product->supplierSourcings()->create([
            'supplier_id' => $supplier->id, 'product_variant_id' => $variant?->id,
            'is_active' => true, 'priority' => 1, 'supplier_cost' => 10,
        ]);
        $alternative = $product->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id, 'product_variant_id' => $variant?->id,
            'is_active' => true, 'priority' => 2, 'supplier_cost' => 12,
        ]);
        $sale = SalesOrder::factory()->create();
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $sale->id, 'product_id' => $product->id,
            'product_variant_id' => $variant?->id, 'quantity_ordered' => 5,
        ]);
        $sale->markAsConfirmed();
        $allocation = SalesOrderItemAllocation::recordFor($item);
        $purchase = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$allocation]))['created'][0];
        $purchaseItem = $purchase->items()->first();
        $purchase->cancel();

        return compact('product', 'variant', 'supplier', 'source', 'alternative', 'sale', 'item', 'allocation', 'purchase', 'purchaseItem');
    }

    public function test_reprise_additive_exacte_sans_effet_sur_achat_stock_ou_statut(): void
    {
        $s = self::scenario();
        $before = $s['allocation']->fresh()->getAttributes();
        $purchaseBefore = $s['purchase']->fresh()->getAttributes();
        $lineBefore = $s['purchaseItem']->fresh()->getAttributes();
        $offer = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        $this->assertDatabaseCount('sales_order_item_allocations', 1);
        $replacement = SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
        $this->assertSame(5, $replacement->quantity);
        $this->assertSame($s['allocation']->id, $replacement->replaces_allocation_id);
        $this->assertSame($s['alternative']->id, $replacement->supplier_product_sourcing_id);
        $this->assertSame($replacement->id, $s['item']->fresh()->allocation->id);
        $this->assertSame($before, $s['allocation']->fresh()->getAttributes());
        $this->assertSame($purchaseBefore, $s['purchase']->fresh()->getAttributes());
        $this->assertSame($lineBefore, $s['purchaseItem']->fresh()->getAttributes());
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_order_items', 1);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0, $s['product']->fresh()->stock);
        $this->assertSame(SalesOrder::STATUS_CONFIRMED, $s['sale']->fresh()->status);
    }

    public static function invalidStates(): array
    {
        return [
            'achat brouillon' => ['purchase_orders', 'status', 'draft'],
            'achat commandé' => ['purchase_orders', 'status', 'ordered'],
            'achat partiel' => ['purchase_orders', 'status', 'partially_received'],
            'achat reçu' => ['purchase_orders', 'status', 'received'],
            'vente brouillon' => ['sales_orders', 'status', 'draft'],
            'vente annulée' => ['sales_orders', 'status', 'cancelled'],
            'vente partielle' => ['sales_orders', 'status', 'partially_shipped'],
            'vente expédiée' => ['sales_orders', 'status', 'shipped'],
            'réception partielle' => ['purchase_order_items', 'quantity_received', 1],
            'réception complète' => ['purchase_order_items', 'quantity_received', 5],
            'expédition' => ['sales_order_items', 'quantity_shipped', 1],
            'quantité achat divergente' => ['purchase_order_items', 'quantity_ordered', 4],
            'quantité vente divergente' => ['sales_order_items', 'quantity_ordered', 4],
            'quantité allocation divergente' => ['sales_order_item_allocations', 'quantity', 4],
            'quantité nulle' => ['sales_order_item_allocations', 'quantity', 0],
            'fournisseur incohérent' => ['purchase_orders', 'supplier_id', null],
            'allocation détachée' => ['purchase_order_items', 'sales_order_item_allocation_id', null],
        ];
    }

    #[DataProvider('invalidStates')]
    public function test_execution_recontrole_les_donnees_apres_confirmation(string $table, string $column, mixed $value): void
    {
        $s = self::scenario();
        $offer = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        // Injection de données incohérentes pour caractériser le refus, sans contourner le code de production.
        DB::table($table)->update([$column => $value]);
        $grouped = SalesOrderItemAllocation::previewCancelledPurchaseRecoveriesFor($s['sale']);
        $this->assertSame([], $grouped['offers']);
        try {
            SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
            $this->fail('La reprise aurait dû être refusée.');
        } catch (\Exception $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertDatabaseCount('sales_order_item_allocations', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_toutes_les_lignes_achat_et_vente_sont_controlees(): void
    {
        $s = self::scenario();
        $other = \App\Models\PurchaseOrderItem::factory()->create();
        $offer = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        DB::table('purchase_order_items')->where('id', $other->id)->update([
            'purchase_order_id' => $s['purchase']->id, 'quantity_received' => 1,
        ]);
        try {
            SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
            $this->fail('Réception sur une autre ligne ignorée.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('réception', $e->getMessage());
        }
        DB::table('purchase_order_items')->where('id', $other->id)->update(['quantity_received' => 0]);
        $otherSaleItem = SalesOrderItem::factory()->create();
        DB::table('sales_order_items')->where('id', $otherSaleItem->id)->update([
            'sales_order_id' => $s['sale']->id, 'quantity_shipped' => 1,
        ]);
        $this->expectExceptionMessage('sans aucune expédition');
        SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
    }

    public function test_retour_enregistre_meme_avec_reception_nulle_est_refuse(): void
    {
        $s = self::scenario();
        $offer = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        DB::table('purchase_order_item_returns')->insert([
            'purchase_order_item_id' => $s['purchaseItem']->id, 'product_id' => $s['product']->id,
            'quantity' => 1, 'returned_at' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectExceptionMessage('sans aucune réception ni retour');
        SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
    }

    public function test_double_reprise_avec_instance_perimee_ne_cree_pas_de_doublon(): void
    {
        $s = self::scenario();
        $s['allocation']->load('replacedBy');
        $offer = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
        try {
            SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
            $this->fail('Double reprise acceptée.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('déjà reprise', $e->getMessage());
        }
        $this->assertDatabaseCount('sales_order_item_allocations', 2);
    }

    public function test_alternative_changee_exige_une_nouvelle_confirmation(): void
    {
        $s = self::scenario();
        $offer = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        $s['product']->supplierSourcings()->create([
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id, 'priority' => 0, 'is_active' => true,
        ]);
        $this->expectExceptionMessage('confirmer à nouveau');
        SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
    }

    public function test_alternative_desactivee_ne_reutilise_jamais_le_fournisseur_initial(): void
    {
        $s = self::scenario();
        $offer = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        $s['alternative']->update(['is_active' => false]);
        $this->expectExceptionMessage('Aucun fournisseur alternatif');
        SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
    }

    public function test_variante_conserve_la_priorite_specifique_sans_repli_apres_exclusion(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $s = self::scenario($product, $variant);
        $offer = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        $this->assertSame($s['alternative']->id, $offer['sourcing_id']);
        $s['alternative']->update(['is_active' => false]);
        $product->supplierSourcings()->create(['supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id, 'is_active' => true]);
        $this->expectExceptionMessage('Aucun fournisseur alternatif');
        SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
    }

    public function test_exception_apres_creation_annule_la_reprise_integralement(): void
    {
        $s = self::scenario();
        $offer = SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']);
        $dispatcher = SalesOrderItemAllocation::getEventDispatcher();
        SalesOrderItemAllocation::setEventDispatcher(clone $dispatcher);
        SalesOrderItemAllocation::created(function () { throw new \RuntimeException('échec simulé'); });
        try {
            SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], $offer);
            $this->fail('Exception attendue.');
        } catch (\RuntimeException $e) {
            $this->assertSame('échec simulé', $e->getMessage());
        } finally {
            SalesOrderItemAllocation::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('sales_order_item_allocations', 1);
        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertNull($s['allocation']->fresh()->replacedBy);
    }

    public function test_reprises_successives_et_transversalite(): void
    {
        foreach (['sport', 'bebe', 'moto', 'artisanat'] as $activity) {
            $s = self::scenario(Product::factory()->create(['activity' => $activity]));
            $first = SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']));
            $purchase = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$first]))['created'][0];
            $purchase->cancel();
            $second = SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($first, SalesOrderItemAllocation::previewCancelledPurchaseRecovery($first));
            $this->assertSame($first->id, $second->replaces_allocation_id);
            $this->assertSame($s['source']->id, $second->supplier_product_sourcing_id);
            $this->assertSame(5, $second->quantity);
        }
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
