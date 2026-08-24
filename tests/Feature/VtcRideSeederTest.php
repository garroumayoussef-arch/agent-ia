<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Database\Seeders\DriverSeeder;
use Database\Seeders\FiscalSettingSeeder;
use Database\Seeders\TaxRateSeeder;
use Database\Seeders\VehicleSeeder;
use Database\Seeders\VtcRideSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 5.12a : seeders de démonstration VTC. Aucune règle métier
 * n'est testée ici en tant que telle (VtcRideSeeder délègue entièrement
 * à VtcRide::markAsConfirmed()/cancel(), déjà couverts par
 * VtcRideTest.php) — ces tests vérifient uniquement que le JEU DE
 * DONNÉES généré est correct et rejouable.
 */
class VtcRideSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedVtc(): void
    {
        $this->seed(TaxRateSeeder::class);
        $this->seed(FiscalSettingSeeder::class);
        $this->seed(DriverSeeder::class);
        $this->seed(VehicleSeeder::class);
        $this->seed(VtcRideSeeder::class);
    }

    public function test_le_seeder_genere_plusieurs_chauffeurs_et_vehicules(): void
    {
        $this->seedVtc();

        $this->assertGreaterThanOrEqual(4, Driver::count());
        $this->assertGreaterThanOrEqual(4, Vehicle::count());
    }

    public function test_le_seeder_configure_le_regime_fiscal_vtc(): void
    {
        $this->seedVtc();

        $setting = FiscalSetting::where('activity', FiscalSetting::ACTIVITY_VTC)->first();

        $this->assertNotNull($setting);
        $this->assertNotNull($setting->tax_rate_id);
    }

    public function test_le_seeder_genere_les_3_statuts_de_course(): void
    {
        $this->seedVtc();

        $this->assertGreaterThanOrEqual(1, VtcRide::where('status', VtcRide::STATUS_DRAFT)->count());
        $this->assertGreaterThanOrEqual(1, VtcRide::where('status', VtcRide::STATUS_CANCELLED)->count());
        $this->assertGreaterThanOrEqual(4, VtcRide::where('status', VtcRide::STATUS_CONFIRMED)->count());
    }

    /**
     * Seules les courses CONFIRMÉES alimentent le dashboard
     * (VtcRideOverview) : ce test vérifie explicitement que le
     * brouillon et l'annulée générés n'ont pas de confirmed_at,
     * condition nécessaire pour qu'ils restent exclus de toute
     * statistique.
     */
    public function test_les_courses_non_confirmees_nont_pas_de_confirmed_at(): void
    {
        $this->seedVtc();

        $draft = VtcRide::where('status', VtcRide::STATUS_DRAFT)->first();
        $cancelled = VtcRide::where('status', VtcRide::STATUS_CANCELLED)->first();

        $this->assertNull($draft->confirmed_at);
        $this->assertNull($cancelled->confirmed_at);
    }

    public function test_les_courses_confirmees_sont_reparties_sur_au_moins_4_periodes_distinctes(): void
    {
        $this->seedVtc();

        $periods = VtcRide::where('status', VtcRide::STATUS_CONFIRMED)
            ->get()
            ->map(fn (VtcRide $ride) => $ride->confirmed_at->format('Y-m'))
            ->unique();

        $this->assertGreaterThanOrEqual(4, $periods->count());
    }

    public function test_le_seeder_est_idempotent(): void
    {
        $this->seedVtc();

        $driverCount = Driver::count();
        $vehicleCount = Vehicle::count();
        $rideCount = VtcRide::count();

        // Rejouer les seeders VTC ne doit rien dupliquer ni lever
        // d'exception (VtcRideSeeder ne retente markAsConfirmed()/
        // cancel() que sur un enregistrement fraîchement créé).
        $this->seed(DriverSeeder::class);
        $this->seed(VehicleSeeder::class);
        $this->seed(VtcRideSeeder::class);

        $this->assertSame($driverCount, Driver::count());
        $this->assertSame($vehicleCount, Vehicle::count());
        $this->assertSame($rideCount, VtcRide::count());
    }
}
