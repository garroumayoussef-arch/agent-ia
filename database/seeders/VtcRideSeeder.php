<?php

namespace Database\Seeders;

use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\TaxRate;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Courses VTC de démonstration — étape 5.12a. Doit s'exécuter après
 * DriverSeeder/VehicleSeeder (chauffeurs/véhicules à assigner) et
 * TaxRateSeeder/FiscalSettingSeeder (régime fiscal requis pour
 * confirmer une course, cf. VtcRide::markAsConfirmed()).
 *
 * FiscalSettingSeeder laisse volontairement le régime VTC NON
 * configuré (tax_rate_id = null) sur une base neuve — décision
 * explicite pour ne rien présumer. Ce seeder de DÉMONSTRATION
 * l'outrepasse délibérément en le pointant vers le taux "Transport de
 * voyageurs (VTC)" injecté par TaxRateSeeder : sans cela, aucune
 * course de démo ne pourrait être confirmée. N'affecte en rien le
 * comportement d'une installation réelle qui n'exécute pas ce seeder.
 *
 * Réparti sur plusieurs périodes (confirmed_at) pour alimenter les
 * statistiques du dashboard (VtcRideOverview, étape 5.6b/5.12b) :
 * mois courant, mois précédent, il y a deux mois, année précédente —
 * plus une course en brouillon et une annulée, pour vérifier que
 * seules les courses confirmées y contribuent.
 *
 * Idempotent : références fixes + firstOrCreate ; confirmation et
 * backdatage de confirmed_at UNIQUEMENT sur un enregistrement qui
 * vient d'être créé (wasRecentlyCreated) — rejouer ce seeder sur une
 * course déjà confirmée ne doit jamais retenter markAsConfirmed()
 * (qui lèverait une exception, cf. étape 5.7/5.10) ni retoucher
 * confirmed_at (verrouillé par VtcRide::updating(), étape 5.6a).
 */
class VtcRideSeeder extends Seeder
{
    public function run(): void
    {
        $vtcRate = TaxRate::where('label', 'Transport de voyageurs (VTC)')->first();

        if ($vtcRate === null) {
            // Défensif : ce seeder dépend de TaxRateSeeder (cf.
            // DatabaseSeeder). Sans lui, aucune course ne peut être
            // confirmée — on n'invente pas de taux ici.
            return;
        }

        FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => $vtcRate->id],
        );

        $drivers = Driver::orderBy('id')->take(4)->get()->values();
        $vehicles = Vehicle::orderBy('id')->take(4)->get()->values();

        if ($drivers->count() < 4 || $vehicles->count() < 4) {
            // Défensif : dépend de DriverSeeder/VehicleSeeder.
            return;
        }

        $confirmedRides = [
            // reference, price_ht, driver, vehicle, backdate confirmed_at
            ['VTC-DEMO-001', 80, $drivers[0], $vehicles[0], null],
            ['VTC-DEMO-002', 120, $drivers[1], $vehicles[1], null],
            ['VTC-DEMO-003', 95, $drivers[2], $vehicles[2], fn () => now()->subMonthNoOverflow()],
            ['VTC-DEMO-004', 60, $drivers[0], $vehicles[3], fn () => now()->subMonthNoOverflow()],
            ['VTC-DEMO-005', 150, $drivers[3], $vehicles[0], fn () => now()->subMonthsNoOverflow(2)],
            ['VTC-DEMO-006', 200, $drivers[1], $vehicles[1], fn () => now()->subYearNoOverflow()],
        ];

        foreach ($confirmedRides as [$reference, $priceHt, $driver, $vehicle, $backdate]) {
            $ride = VtcRide::firstOrCreate(
                ['reference' => $reference],
                [
                    'price_ht' => $priceHt,
                    'driver_id' => $driver->id,
                    'vehicle_id' => $vehicle->id,
                ],
            );

            if (! $ride->wasRecentlyCreated) {
                continue;
            }

            $ride->markAsConfirmed();

            if ($backdate !== null) {
                DB::table('vtc_rides')
                    ->where('id', $ride->id)
                    ->update(['confirmed_at' => $backdate()]);
            }
        }

        // Brouillon : ne doit alimenter aucune statistique.
        VtcRide::firstOrCreate(
            ['reference' => 'VTC-DEMO-007'],
            [
                'price_ht' => 70,
                'driver_id' => $drivers[2]->id,
                'vehicle_id' => $vehicles[2]->id,
            ],
        );

        // Annulée : ne doit alimenter aucune statistique.
        $cancelled = VtcRide::firstOrCreate(
            ['reference' => 'VTC-DEMO-008'],
            [
                'price_ht' => 45,
                'driver_id' => $drivers[3]->id,
                'vehicle_id' => $vehicles[3]->id,
            ],
        );

        if ($cancelled->wasRecentlyCreated) {
            $cancelled->cancel();
        }
    }
}
