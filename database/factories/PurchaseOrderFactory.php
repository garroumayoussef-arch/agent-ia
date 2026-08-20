<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'BC-'.now()->format('Ymd').'-'.strtoupper(fake()->unique()->bothify('####')),
            'status' => PurchaseOrder::STATUS_DRAFT,
            'order_date' => null,
            'notes' => null,
        ];
    }
}
