<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\Supplier;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierProductSourcingIdentityTest extends TestCase
{
    use RefreshDatabase;

    public static function scenario(bool $allocated = true, bool $withVariant = false): array
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $variant = $withVariant ? ProductVariant::factory()->create(['product_id' => $product->id]) : null;
        $source = $product->supplierSourcings()->create([
            'supplier_id' => $supplier->id, 'product_variant_id' => $variant?->id,
            'is_active' => true, 'priority' => 1, 'supplier_cost' => 10,
        ]);
        $item = SalesOrderItem::factory()->create([
            'product_id' => $product->id, 'product_variant_id' => $variant?->id,
        ]);
        $allocation = $allocated ? SalesOrderItemAllocation::recordFor($item) : null;

        return compact('product', 'supplier', 'variant', 'source', 'item', 'allocation');
    }

    public static function identityChanges(): array
    {
        return [['supplier_id', false], ['product_id', false], ['product_variant_id', false],
            ['product_variant_id', true], ['variant_to_null', true]];
    }

    #[DataProvider('identityChanges')]
    public function test_identite_referencee_refusee_atomiquement_meme_en_sauvegarde_silencieuse(string $field, bool $variant): void
    {
        $s = self::scenario(true, $variant);
        $value = match ($field) {
            'supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id,
            'product_id' => Product::factory()->create()->id,
            'variant_to_null' => null,
            default => ProductVariant::factory()->create(['product_id' => $s['product']->id])->id,
        };
        $field = $field === 'variant_to_null' ? 'product_variant_id' : $field;
        $before = $s['source']->fresh()->getAttributes();
        foreach ([false, true] as $quiet) {
            $source = $s['source']->fresh();
            $source->fill([$field => $value, 'notes' => 'ne doit pas être sauvegardé']);
            try {
                $quiet ? $source->saveQuietly() : $source->save();
                $this->fail('Identité modifiée malgré une allocation.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
            $this->assertSame($before, $source->fresh()->getAttributes());
        }
    }

    public function test_fiche_non_referencee_reste_modifiable(): void
    {
        $s = self::scenario(false);
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $s['source']->update(['supplier_id' => $supplier->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id]);
        $this->assertSame($product->id, $s['source']->fresh()->product_id);
        $s['source']->update(['product_variant_id' => null]);
        $this->assertNull($s['source']->fresh()->product_variant_id);
    }

    public function test_attributs_operationnels_et_identite_equivalente_restent_modifiables(): void
    {
        $s = self::scenario();
        $s['source']->update([
            'supplier_id' => (string) $s['supplier']->id, 'product_id' => (string) $s['product']->id,
            'product_variant_id' => null, 'is_active' => false, 'priority' => 9,
            'supplier_cost' => 22.50, 'currency' => 'EUR', 'lead_time_days' => 4,
            'min_order_quantity' => 2, 'notes' => 'Opérationnel',
        ]);
        $fresh = $s['source']->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame(9, $fresh->priority);
        $this->assertSame('22.50', $fresh->supplier_cost);
        $this->assertSame('EUR', $fresh->currency);
        $this->assertSame(4, $fresh->lead_time_days);
        $this->assertSame(2, $fresh->min_order_quantity);
        $this->assertSame('Opérationnel', $fresh->notes);
        $result = (new CreatePurchaseOrdersFromAllocations)->execute(new Collection([$s['allocation']->fresh()]));
        $this->assertSame('22.50', $result['created'][0]->items()->first()->unit_price);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_instance_chargee_avant_allocation_ne_contourne_pas_la_garde(): void
    {
        $s = self::scenario(false);
        $this->assertFalse($s['source']->hasAllocationHistory());
        SalesOrderItemAllocation::recordFor($s['item']);
        $this->assertTrue($s['source']->hasAllocationHistory());
        $this->expectException(ValidationException::class);
        $s['source']->update(['supplier_id' => Supplier::factory()->create(['name' => fake()->company()])->id]);
    }

    public function test_instance_perimee_operationnelle_ne_reecrit_pas_lidentite(): void
    {
        $s = self::scenario(false);
        $stale = $s['source']->fresh();
        $supplier = Supplier::factory()->create(['name' => fake()->company()]);
        $s['source']->update(['supplier_id' => $supplier->id]);
        SalesOrderItemAllocation::recordFor($s['item']);
        $stale->update(['notes' => 'édition permise']);
        $this->assertSame($supplier->id, $stale->fresh()->supplier_id);
        $this->assertSame('édition permise', $stale->fresh()->notes);
    }

    public function test_achat_annule_et_allocation_remplacee_conservent_la_protection(): void
    {
        $s = SalesOrderCancelledPurchaseRecoveryTest::scenario();
        SalesOrderItemAllocation::recoverAfterCancelledPurchaseFor($s['allocation'], SalesOrderItemAllocation::previewCancelledPurchaseRecovery($s['allocation']));
        $this->assertNotNull($s['allocation']->fresh()->replacedBy);
        $this->expectException(ValidationException::class);
        $s['source']->update(['product_id' => Product::factory()->create()->id]);
    }
}
