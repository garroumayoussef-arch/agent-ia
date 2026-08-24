<?php

namespace Database\Seeders;

use App\Models\Driver;
use Illuminate\Database\Seeder;

/**
 * Chauffeurs de démonstration — étape 5.12a. Aucun user_id lié : un
 * chauffeur n'a pas forcément de compte système (cf. Driver.php), et
 * le scoping chauffeur/admin est déjà couvert par ses propres tests
 * dédiés (VtcRideAuthorizationTest.php) — ce seeder n'a pas vocation à
 * re-tester cette règle, seulement à fournir des données pour le
 * dashboard.
 */
class DriverSeeder extends Seeder
{
    public function run(): void
    {
        $drivers = [
            ['name' => 'Karim Benali', 'license_number' => 'VTC-75-001', 'phone' => '0601020304'],
            ['name' => 'Sophie Marchand', 'license_number' => 'VTC-75-002', 'phone' => '0601020305'],
            ['name' => 'Youssef Amrani', 'license_number' => 'VTC-75-003', 'phone' => '0601020306'],
            ['name' => 'Claire Dubois', 'license_number' => 'VTC-75-004', 'phone' => '0601020307'],
        ];

        foreach ($drivers as $driver) {
            Driver::firstOrCreate(
                ['name' => $driver['name']],
                $driver,
            );
        }
    }
}
