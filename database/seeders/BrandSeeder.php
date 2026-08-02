<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Brand;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brands = [

            // Sport
            ['name' => 'Nike'],
            ['name' => 'Adidas'],
            ['name' => 'Puma'],
            ['name' => 'New Balance'],
            ['name' => 'Joma'],
            ['name' => 'Macron'],
            ['name' => 'Umbro'],
            ['name' => 'Hummel'],
            ['name' => 'Kappa'],
            ['name' => 'Mizuno'],
            ['name' => 'Asics'],
            ['name' => 'Under Armour'],
            ['name' => 'Reebok'],
            ['name' => 'Kelme'],
            ['name' => 'Erreà'],
            ['name' => 'Le Coq Sportif'],
            ['name' => 'Diadora'],
            ['name' => 'Lotto'],
            ['name' => 'Castore'],
            ['name' => 'Craft'],

            // Sneakers
            ['name' => 'Jordan'],
            ['name' => 'Converse'],
            ['name' => 'Vans'],
            ['name' => 'Skechers'],
            ['name' => 'Salomon'],
            ['name' => 'Merrell'],
            ['name' => 'The North Face'],
            ['name' => 'Columbia'],

            // Luxe
            ['name' => 'Louis Vuitton'],
            ['name' => 'Gucci'],
            ['name' => 'Dior'],
            ['name' => 'Hermès'],
            ['name' => 'Prada'],
            ['name' => 'Balenciaga'],
            ['name' => 'Burberry'],
            ['name' => 'Versace'],
            ['name' => 'Fendi'],
            ['name' => 'Givenchy'],
            ['name' => 'Valentino'],
            ['name' => 'Dolce & Gabbana'],
            ['name' => 'Bottega Veneta'],
            ['name' => 'Saint Laurent'],
            ['name' => 'Celine'],
            ['name' => 'Loewe'],
            ['name' => 'Moncler'],
            ['name' => 'Kenzo'],
            ['name' => 'Balmain'],
            ['name' => 'Off-White'],
            ['name' => 'Palm Angels'],
            ['name' => 'Stone Island'],
            ['name' => 'Amiri'],
            ['name' => 'Tom Ford'],
            ['name' => 'Brunello Cucinelli'],
            ['name' => 'Loro Piana'],
            ['name' => 'Zegna'],

            // Horlogerie
            ['name' => 'Rolex'],
            ['name' => 'Patek Philippe'],
            ['name' => 'Audemars Piguet'],
            ['name' => 'Richard Mille'],
            ['name' => 'Omega'],
            ['name' => 'Cartier'],
            ['name' => 'Breitling'],
            ['name' => 'TAG Heuer'],
            ['name' => 'Hublot'],

            // Outdoor
            ['name' => 'Patagonia'],
            ['name' => 'Arc’teryx'],

            // Autres
            ['name' => 'Lacoste'],
            ['name' => 'Tommy Hilfiger'],
            ['name' => 'Calvin Klein'],
            ['name' => 'Ralph Lauren'],
            ['name' => 'Hugo Boss'],
            ['name' => 'Armani'],
            ['name' => 'Diesel'],
            ['name' => 'Superdry'],
        ];

        foreach ($brands as $brand) {
            Brand::firstOrCreate([
                'name' => $brand['name'],
            ]);
        }
    }
}