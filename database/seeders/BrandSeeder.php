<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Brand;
use Illuminate\Support\Str;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brands = [

            // =========================
            // ⚽ SPORT
            // =========================
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

            // =========================
            // 👟 SNEAKERS
            // =========================
            ['name' => 'Jordan'],
            ['name' => 'Converse'],
            ['name' => 'Vans'],
            ['name' => 'Skechers'],
            ['name' => 'Salomon'],
            ['name' => 'Merrell'],
            ['name' => 'The North Face'],
            ['name' => 'Columbia'],

            // =========================
            // 💎 LUXE / MODE
            // =========================
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
            ['name' => 'Armani'],
            ['name' => 'Diesel'],
            ['name' => 'Superdry'],
            ['name' => 'Lacoste'],
            ['name' => 'Tommy Hilfiger'],
            ['name' => 'Calvin Klein'],
            ['name' => 'Ralph Lauren'],
            ['name' => 'Hugo Boss'],

            // =========================
            // ⌚ HORLOGERIE
            // =========================
            ['name' => 'Rolex'],
            ['name' => 'Patek Philippe'],
            ['name' => 'Audemars Piguet'],
            ['name' => 'Richard Mille'],
            ['name' => 'Omega'],
            ['name' => 'Cartier'],
            ['name' => 'Breitling'],
            ['name' => 'TAG Heuer'],
            ['name' => 'Hublot'],

            // =========================
            // 🏕️ OUTDOOR
            // =========================
            ['name' => 'Patagonia'],
            ['name' => "Arc'teryx"],

            // =========================
            // 🚗 AUTOMOBILE
            // =========================
            ['name' => 'Mercedes-Benz'],
            ['name' => 'BMW'],
            ['name' => 'Audi'],
            ['name' => 'Volkswagen'],
            ['name' => 'Porsche'],
            ['name' => 'Toyota'],
            ['name' => 'Honda'],
            ['name' => 'Ford'],
            ['name' => 'Tesla'],
            ['name' => 'Peugeot'],
            ['name' => 'Renault'],
            ['name' => 'Citroën'],
            ['name' => 'Dacia'],
            ['name' => 'Fiat'],
            ['name' => 'Land Rover'],
            ['name' => 'Range Rover'],
            ['name' => 'Volvo'],
            ['name' => 'Lexus'],
            ['name' => 'Nissan'],
            ['name' => 'Hyundai'],
            ['name' => 'Kia'],

            // =========================
            // 📱 HIGH-TECH
            // =========================
            ['name' => 'Apple'],
            ['name' => 'Samsung'],
            ['name' => 'Google'],
            ['name' => 'Xiaomi'],
            ['name' => 'Huawei'],
            ['name' => 'OnePlus'],
            ['name' => 'Oppo'],
            ['name' => 'Sony'],
            ['name' => 'LG'],
            ['name' => 'Lenovo'],
            ['name' => 'Asus'],
            ['name' => 'Acer'],
            ['name' => 'Microsoft'],

            // =========================
            // 🎮 GAMING
            // =========================
            ['name' => 'PlayStation'],
            ['name' => 'Xbox'],
            ['name' => 'Nintendo'],
            ['name' => 'Valve'],
            ['name' => 'Razer'],
            ['name' => 'Logitech'],
            ['name' => 'SteelSeries'],
            ['name' => 'Corsair'],

            // =========================
            // 🏠 AUTRES
            // =========================
            ['name' => 'Dyson'],
            ['name' => 'Philips'],
            ['name' => 'Bosch'],
            ['name' => 'DeWalt'],
            ['name' => 'Makita'],
            ['name' => 'Stanley'],
        ];

        foreach ($brands as $brand) {
            $slug = Str::slug($brand['name']);

            Brand::firstOrCreate([
                'name' => $brand['name'],
                'slug' => $slug,
            ]);
        }
    }
}