<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            BrandSeeder::class,
            CategorySeeder::class,
            CompetitionSeeder::class,
            ClubSeeder::class,
            SupplierSeeder::class,
            // ProductSeeder s'exécute en dernier : il s'appuie sur les
            // données injectées par les seeders ci-dessus pour associer
            // ses produits de démo à une marque/catégorie/club/etc.
            ProductSeeder::class,
        ]);
    }
}