<?php

namespace Database\Factories;

use App\Models\SalesOrderItem;
use App\Models\SalesOrderItemAllocation;
use App\Models\SupplierProductSourcing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrderItemAllocation>
 */
class SalesOrderItemAllocationFactory extends Factory
{
    protected $model = SalesOrderItemAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_order_item_id' => SalesOrderItem::factory(),
            'supplier_product_sourcing_id' => SupplierProductSourcing::factory(),
            'quantity' => 1,
        ];
    }
}
