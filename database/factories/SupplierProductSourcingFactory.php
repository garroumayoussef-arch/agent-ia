<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProductSourcing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierProductSourcing>
 */
class SupplierProductSourcingFactory extends Factory
{
    protected $model = SupplierProductSourcing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'priority' => 100,
            'supplier_cost' => fake()->randomFloat(2, 5, 200),
            'currency' => null,
            'lead_time_days' => fake()->numberBetween(1, 30),
            'min_order_quantity' => null,
            'is_active' => true,
            'notes' => null,
        ];
    }
}
