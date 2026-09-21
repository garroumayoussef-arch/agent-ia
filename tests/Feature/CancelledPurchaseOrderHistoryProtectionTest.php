<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CancelledPurchaseOrderHistoryProtectionTest extends TestCase
{
    use RefreshDatabase;

    public static function mutations(): array
    {
        $cases = [];
        foreach (['delete_order', 'delete_item', 'status', 'supplier_id', 'reference', 'detach', 'move', 'quantity', 'add', 'product', 'source', 'supplier'] as $action) {
            foreach ([false, true] as $recovered) {
                $cases[$action.($recovered ? '_apres' : '_avant')] = [$action, $recovered];
            }
        }

        return $cases;
    }

    #[DataProvider('mutations')]
    public function test_historique_protege_avant_et_apres_reprise(string $action, bool $recovered): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        if ($recovered) {
            SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']));
        }
        $before = $s['purchase']->fresh()->getAttributes();
        $lineBefore = $s['purchaseItem']->fresh()->getAttributes();
        try {
            match ($action) {
                'delete_order' => $s['purchase']->delete(),
                'delete_item' => $s['purchaseItem']->delete(),
                'status' => $s['purchase']->update(['status' => 'draft']),
                'supplier_id' => $s['purchase']->update(['supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id]),
                'reference' => $s['purchase']->update(['reference' => 'CHANGED']),
                'detach' => $s['purchaseItem']->update(['sales_order_item_allocation_id' => null]),
                'move' => $s['purchaseItem']->update(['purchase_order_id' => PurchaseOrder::factory()->create()->id]),
                'quantity' => $s['purchaseItem']->update(['quantity_ordered' => 2]),
                'add' => PurchaseOrderItem::factory()->create(['purchase_order_id' => $s['purchase']->id]),
                'product' => $s['product']->delete(),
                'source' => $s['source']->delete(),
                'supplier' => $s['supplier']->delete(),
            };
            $this->fail('Mutation historique acceptée : '.$action);
        } catch (\Exception $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $s['purchase']->fresh()->getAttributes());
        $this->assertSame($lineBefore, $s['purchaseItem']->fresh()->getAttributes());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_instances_perimees_et_relations_chargees_ne_contournent_pas_la_protection(): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        DB::table('purchase_orders')->where('id', $s['purchase']->id)->update(['status' => 'draft']);
        $staleOrder = $s['purchase']->fresh();
        $staleItem = $s['purchaseItem']->fresh()->load('purchaseOrder');
        $s['purchase']->fresh()->cancel();
        foreach ([fn () => $staleOrder->delete(), fn () => $staleOrder->update(['discount_amount' => 5]), fn () => $staleItem->delete(), fn () => $staleItem->update(['sales_order_item_allocation_id' => null])] as $mutation) {
            try {
                $mutation();
                $this->fail('Instance périmée acceptée.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('annulé', $e->getMessage());
            }
        }
        $this->assertSame('cancelled', $s['purchase']->fresh()->status);
        $this->assertSame($s['allocation']->id, $s['purchaseItem']->fresh()->sales_order_item_allocation_id);
    }

    public function test_variante_referencee_ne_peut_pas_perdre_sa_trace(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario($product, $variant);
        try {
            $variant->delete();
            $this->fail('Suppression de variante acceptée.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('référencée', $e->getMessage());
        }
        $this->assertSame($variant->id, $s['purchaseItem']->fresh()->product_variant_id);
    }

    public function test_achats_directs_et_brouillons_ordinaires_restent_supprimables(): void
    {
        foreach (['draft', 'cancelled'] as $status) {
            $purchase = PurchaseOrder::factory()->create(['status' => $status]);
            $item = PurchaseOrderItem::factory()->create(['purchase_order_id' => $purchase->id]);
            $item->delete();
            $purchase->delete();
            $this->assertModelMissing($item);
            $this->assertModelMissing($purchase);
        }
    }
}
