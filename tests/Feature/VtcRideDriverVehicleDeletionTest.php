<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\TaxRate;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 5.11 : Driver/Vehicle sont référencés par vtc_rides.driver_id/
 * vehicle_id en nullOnDelete — ces tests vérifient que le modèle refuse
 * la suppression tant qu'une VtcRide (brouillon ou confirmée, décision
 * explicite) existe, plutôt que de laisser la base de données effacer
 * silencieusement la trace de qui/quel véhicule était réellement
 * impliqué. Même principe que ProductDeletionIntegrityTest.php
 * (commit 1f92d20) pour ProductVariant, un cas structurellement
 * identique (FK nullOnDelete).
 *
 * Hors périmètre, volontairement : Customer (partagé avec SalesOrder,
 * non traité ici).
 */
class VtcRideDriverVehicleDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeDriver(array $attributes = []): Driver
    {
        return Driver::create(array_merge(['name' => 'Chauffeur Test'], $attributes));
    }

    private function makeVehicle(array $attributes = []): Vehicle
    {
        return Vehicle::create(array_merge(['plate_number' => 'AA-'.uniqid().'-ZZ'], $attributes));
    }

    private function setVtcFiscalSetting(TaxRate $taxRate): FiscalSetting
    {
        return FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => $taxRate->id],
        );
    }

    /*
     * =================================================================
     * Cas normal : suppression autorisée sans aucune VtcRide associée
     * =================================================================
     */

    public function test_un_chauffeur_sans_course_peut_etre_supprime(): void
    {
        $driver = $this->makeDriver();

        $driver->delete();

        $this->assertDatabaseMissing('drivers', ['id' => $driver->id]);
    }

    public function test_un_vehicule_sans_course_peut_etre_supprime(): void
    {
        $vehicle = $this->makeVehicle();

        $vehicle->delete();

        $this->assertDatabaseMissing('vehicles', ['id' => $vehicle->id]);
    }

    /*
     * =================================================================
     * Bloqué dès qu'UNE VtcRide existe, brouillon compris
     * =================================================================
     */

    public function test_un_chauffeur_reference_par_une_course_en_brouillon_ne_peut_pas_etre_supprime(): void
    {
        $driver = $this->makeDriver();
        VtcRide::create(['reference' => 'VTC-DEL-1', 'driver_id' => $driver->id]);

        $this->expectException(\Exception::class);
        $driver->delete();
    }

    public function test_un_vehicule_reference_par_une_course_en_brouillon_ne_peut_pas_etre_supprime(): void
    {
        $vehicle = $this->makeVehicle();
        VtcRide::create(['reference' => 'VTC-DEL-2', 'vehicle_id' => $vehicle->id]);

        $this->expectException(\Exception::class);
        $vehicle->delete();
    }

    public function test_un_chauffeur_reference_par_une_course_confirmee_ne_peut_pas_etre_supprime(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $driver = $this->makeDriver();
        $ride = VtcRide::create([
            'reference' => 'VTC-DEL-3',
            'price_ht' => 100,
            'driver_id' => $driver->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        $this->expectException(\Exception::class);
        $driver->delete();
    }

    public function test_un_vehicule_reference_par_une_course_confirmee_ne_peut_pas_etre_supprime(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $vehicle = $this->makeVehicle();
        $ride = VtcRide::create([
            'reference' => 'VTC-DEL-4',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $vehicle->id,
        ]);
        $ride->markAsConfirmed();

        $this->expectException(\Exception::class);
        $vehicle->delete();
    }

    /*
     * =================================================================
     * La suppression refusée ne laisse aucun effet de bord
     * =================================================================
     */

    public function test_le_chauffeur_nest_pas_supprime_apres_une_tentative_refusee(): void
    {
        $driver = $this->makeDriver();
        VtcRide::create(['reference' => 'VTC-DEL-5', 'driver_id' => $driver->id]);

        try {
            $driver->delete();
        } catch (\Throwable $e) {
            // Attendu — on vérifie seulement l'absence d'effet de bord.
        }

        $this->assertDatabaseHas('drivers', ['id' => $driver->id]);
    }

    public function test_le_vehicule_nest_pas_supprime_apres_une_tentative_refusee(): void
    {
        $vehicle = $this->makeVehicle();
        VtcRide::create(['reference' => 'VTC-DEL-6', 'vehicle_id' => $vehicle->id]);

        try {
            $vehicle->delete();
        } catch (\Throwable $e) {
            // Attendu — on vérifie seulement l'absence d'effet de bord.
        }

        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }
}
