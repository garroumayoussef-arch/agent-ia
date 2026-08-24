<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 5.1 : uniquement migrations + modèles + relations, aucune
 * logique métier (résolution du taux, calculs, verrouillage) — ces
 * tests vérifient donc la structure, pas encore le comportement, qui
 * sera couvert à l'étape 5.2.
 */
class VtcRideModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_seeder_cree_le_fiscal_setting_vtc_non_configure(): void
    {
        $this->seed(\Database\Seeders\FiscalSettingSeeder::class);

        $setting = FiscalSetting::where('activity', FiscalSetting::ACTIVITY_VTC)->first();

        $this->assertNotNull($setting);
        $this->assertNull($setting->tax_rate_id);
    }

    public function test_un_driver_peut_etre_cree_sans_compte_user(): void
    {
        $driver = Driver::create([
            'name' => 'Jean Chauffeur',
            'license_number' => 'ABC123',
        ]);

        $this->assertNull($driver->user_id);
        $this->assertNull($driver->user);
        // is_active a un défaut DB (true) : non reflété en mémoire tant
        // que rien ne le fixe explicitement (aucun hook à cette étape
        // 5.1, volontairement — cf. étape 5.2), d'où le ->fresh().
        $this->assertTrue($driver->fresh()->is_active);
    }

    public function test_un_driver_peut_etre_lie_a_un_compte_user(): void
    {
        $user = User::factory()->create();

        $driver = Driver::create([
            'name' => 'Jean Chauffeur',
            'user_id' => $user->id,
        ]);

        $this->assertSame($user->id, $driver->user->id);
    }

    public function test_un_vehicle_peut_etre_cree(): void
    {
        $vehicle = Vehicle::create([
            'plate_number' => 'AA-123-BB',
            'brand' => 'Renault',
            'model' => 'Zoe',
        ]);

        $this->assertTrue($vehicle->fresh()->is_active);
    }

    public function test_une_course_vtc_peut_etre_creee_avec_le_minimum_de_champs(): void
    {
        $ride = VtcRide::create([
            'reference' => 'VTC-TEST-1',
        ]);

        // status a un défaut DB ('draft') : le hook qui le fixerait
        // explicitement en mémoire dès la création (à l'image de
        // PurchaseOrder/SalesOrder::creating()) est volontairement
        // l'objet de l'étape 5.2, pas de celle-ci — d'où le ->fresh().
        $this->assertSame(VtcRide::STATUS_DRAFT, $ride->fresh()->status);
        $this->assertNull($ride->customer_id);
        $this->assertNull($ride->driver_id);
        $this->assertNull($ride->vehicle_id);
    }

    public function test_une_course_vtc_expose_toutes_ses_relations(): void
    {
        $this->seed(\Database\Seeders\TaxRateSeeder::class);

        $customer = Customer::create(['name' => 'Jean Dupont']);
        $driver = Driver::create(['name' => 'Chauffeur Test']);
        $vehicle = Vehicle::create(['plate_number' => 'AA-999-ZZ']);
        $taxRate = TaxRate::where('rate', 10)->first();
        $user = User::factory()->create();

        $ride = VtcRide::create([
            'reference' => 'VTC-TEST-2',
            'customer_id' => $customer->id,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'tax_rate_id' => $taxRate->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($ride->customer->is($customer));
        $this->assertTrue($ride->driver->is($driver));
        $this->assertTrue($ride->vehicle->is($vehicle));
        $this->assertTrue($ride->taxRate->is($taxRate));
        $this->assertTrue($ride->user->is($user));

        $this->assertTrue($customer->vtcRides->contains($ride));
        $this->assertTrue($driver->vtcRides->contains($ride));
        $this->assertTrue($vehicle->vtcRides->contains($ride));
    }

    /**
     * Aucune logique métier n'existe encore (étape 5.2), mais la
     * structure elle-même ne doit avoir strictement aucun lien avec le
     * stock : aucune colonne product_id/product_variant_id, aucune
     * table pivot, rien.
     */
    public function test_une_course_vtc_ne_cree_aucun_mouvement_de_stock(): void
    {
        VtcRide::create(['reference' => 'VTC-TEST-3']);

        $this->assertSame(0, StockMovement::count());
    }
}
