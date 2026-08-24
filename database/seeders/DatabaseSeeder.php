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
            TaxRateSeeder::class,
            FiscalSettingSeeder::class,
            // Données de démo VTC (étape 5.12a) : DriverSeeder/
            // VehicleSeeder avant VtcRideSeeder (chauffeurs/véhicules à
            // assigner), tous deux après TaxRateSeeder/
            // FiscalSettingSeeder (régime fiscal requis pour confirmer
            // une course de démo).
            DriverSeeder::class,
            VehicleSeeder::class,
            VtcRideSeeder::class,
            BrandSeeder::class,
            CategorySeeder::class,
            CompetitionSeeder::class,
            ClubSeeder::class,
            SupplierSeeder::class,
            // ProductSeeder s'exécute en dernier avant WarehouseSeeder : il
            // s'appuie sur les données injectées par les seeders ci-dessus
            // pour associer ses produits de démo à une marque/catégorie/
            // club/etc.
            ProductSeeder::class,
            // Étape T10 : WarehouseSeeder lit products/product_variants.warehouse
            // en lecture seule (jamais en écriture) — doit donc s'exécuter
            // après ProductSeeder pour réconcilier les valeurs réellement
            // présentes.
            WarehouseSeeder::class,
            // Étape T11a : WarehouseStockSeeder dépend de l'entrepôt
            // is_default créé par WarehouseSeeder juste au-dessus — doit
            // s'exécuter après lui.
            WarehouseStockSeeder::class,
        ]);
    }
}