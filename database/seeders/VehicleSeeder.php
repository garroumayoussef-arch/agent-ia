<?php

namespace Database\Seeders;

use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * Véhicules de démonstration — étape 5.12a.
 */
class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $vehicles = [
            ['plate_number' => 'AB-123-CD', 'brand' => 'Peugeot', 'model' => '508'],
            ['plate_number' => 'EF-456-GH', 'brand' => 'Volkswagen', 'model' => 'Passat'],
            ['plate_number' => 'IJ-789-KL', 'brand' => 'Mercedes', 'model' => 'Classe E'],
            ['plate_number' => 'MN-012-OP', 'brand' => 'Tesla', 'model' => 'Model 3'],
        ];

        foreach ($vehicles as $vehicle) {
            Vehicle::firstOrCreate(
                ['plate_number' => $vehicle['plate_number']],
                $vehicle,
            );
        }
    }
}
