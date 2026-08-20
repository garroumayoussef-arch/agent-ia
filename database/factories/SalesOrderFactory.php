<?php

namespace Database\Factories;

use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'CMD-'.now()->format('Ymd').'-'.strtoupper(fake()->unique()->bothify('####')),
            'status' => SalesOrder::STATUS_DRAFT,
            'order_date' => null,
            'notes' => null,
        ];
    }
}
