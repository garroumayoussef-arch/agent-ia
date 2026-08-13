<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Football',
            'Basketball',
            'Tennis',
            'Running',
            'Fitness',
            'Cyclisme',
            'Sports de combat',
            'Sports nautiques',
            'Sports automobiles',
            'Sports mécaniques',
            'Équipement sportif',
            'Vêtements de sport',
            'Chaussures de sport',
            'Accessoires de sport',
            'Électronique',
            'Téléphones',
            'Informatique',
            'Consoles de jeux',
            'Jeux vidéo',
            'Automobile',
            'Moto',
            'Mode',
            'Chaussures',
            'Luxe',
            'Maison',
            'Électroménager',
            'Bijoux',
            'Montres',
            'Collections',
            'Autres',
        ];

        foreach ($categories as $name) {
            Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
        }
    }
}