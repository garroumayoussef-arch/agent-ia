<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * Define the model's default state.
     *
     * `product_id` par défaut sur `Product::factory()` : appeler
     * `ProductVariant::factory()->create()` seul crée automatiquement
     * son produit parent (convention Laravel standard). Un appelant qui
     * veut rattacher la variante à un produit précis passe
     * `->for($product)` ou `->create(['product_id' => $product->id])`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => 'VAR-'.strtoupper(fake()->unique()->bothify('##########')),
            'barcode' => fake()->unique()->ean13(),
            'size' => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL']),
            'color' => fake()->randomElement(['Noir', 'Blanc', 'Bleu', 'Rouge', 'Vert', 'Jaune']),
            'version' => fake()->randomElement(['Fan Version', 'Player Version', 'Kids', 'Training']),
            'stock' => fake()->numberBetween(0, 50),
            'prix_achat' => fake()->randomFloat(2, 5, 60),
            'prix_vente' => fake()->randomFloat(2, 20, 150),
            'warehouse' => 'France',
            'status' => 'active',
        ];
    }
}
