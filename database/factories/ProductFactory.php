<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * Volontairement SANS brand_id/category_id/club_id/competition_id/
     * supplier_id ni categorie/marque/fournisseur : ces colonnes sont
     * nullable et les champs "miroirs" sont recalculés automatiquement
     * par Product::booted() dès qu'une relation est renseignée (voir
     * syncMirroredRelationField). Un test ou un seeder qui a besoin
     * d'un produit associé à une marque/catégorie/etc. le précise
     * explicitement via ->create(['brand_id' => ..., ...]).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nom = ucfirst(fake()->words(3, true));

        return [
            'reference' => 'REF-'.strtoupper(fake()->unique()->bothify('########')),
            'nom' => $nom,
            'slug' => Str::slug($nom).'-'.fake()->unique()->numberBetween(1000, 999999),
            'type' => fake()->randomElement([
                'Player Version',
                'Fan Version',
                'Kit Enfant',
                'Training',
                'Veste',
                'Pantalon',
                'Short',
            ]),
            'taille' => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL', '2XL']),
            // Le stock d'un produit avec variantes est recalculé par
            // ProductVariant::syncProductStock() : cette valeur ne sert
            // que pour un produit SANS variante.
            'stock' => 0,
            'prix_achat' => fake()->randomFloat(2, 5, 60),
            'prix_vente' => fake()->randomFloat(2, 20, 150),
            'sku' => 'SKU-'.strtoupper(fake()->unique()->bothify('##########')),
            'barcode' => fake()->unique()->ean13(),
            'season' => fake()->randomElement(['2023-2024', '2024-2025', '2025-2026']),
            'version' => fake()->randomElement(['Fan', 'Player']),
            'featured' => fake()->boolean(20),
            'status' => true,
        ];
    }
}
