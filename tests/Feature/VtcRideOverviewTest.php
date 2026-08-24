<?php

namespace Tests\Feature;

use App\Filament\Widgets\VtcRideOverview;
use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape 5.6b : widget/dashboard VTC. Statistiques des courses
 * CONFIRMÉES uniquement, période "ce mois" basée sur confirmed_at
 * (étape 5.6a), scoping chauffeur/admin identique à VtcRideResource via
 * ScopesToOwnDriver (étape 5.6a). Aucune règle fiscale n'est testée ici
 * en tant que telle (déjà couverte par VtcRideTest.php) : ces tests
 * portent uniquement sur QUOI est agrégé et QUI le voit.
 */
class VtcRideOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeDriver(array $attributes = []): Driver
    {
        return Driver::create(array_merge([
            'name' => 'Chauffeur Test',
        ], $attributes));
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ']);
    }

    private function setVtcFiscalSetting(TaxRate $taxRate): FiscalSetting
    {
        return FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => $taxRate->id],
        );
    }

    /**
     * Crée et confirme une course pour un chauffeur donné. Contourne la
     * garde updating() sur confirmed_at (comme markAsConfirmed() le
     * fait légitimement en interne) en passant par le flux normal —
     * aucun contournement ici, c'est le seul moyen d'obtenir une course
     * confirmée.
     */
    private function makeConfirmedRide(Driver $driver, float $priceHt, string $reference): VtcRide
    {
        $ride = VtcRide::create([
            'reference' => $reference,
            'price_ht' => $priceHt,
            'driver_id' => $driver->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        $ride->markAsConfirmed();

        return $ride->fresh();
    }

    /**
     * Force confirmed_at à une date arbitraire sans passer par Eloquent
     * (la garde updating() rejette toute modification de confirmed_at
     * une fois fixée, cf. étape 5.6a) — update() SQL brut, exactement
     * pour simuler une course confirmée à une période antérieure.
     */
    private function backdateConfirmedAtTo(VtcRide $ride, \Carbon\Carbon $date): void
    {
        DB::table('vtc_rides')
            ->where('id', $ride->id)
            ->update(['confirmed_at' => $date]);
    }

    private function backdateConfirmedAtToLastMonth(VtcRide $ride): void
    {
        $this->backdateConfirmedAtTo($ride, now()->subMonthNoOverflow()->startOfMonth()->addDay());
    }

    /*
     * =================================================================
     * canView() — même règle d'accès que VtcRideResource
     * =================================================================
     */

    public function test_le_widget_est_visible_pour_un_admin(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->assertTrue(VtcRideOverview::canView());
    }

    public function test_le_widget_est_visible_pour_un_manager(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->assertTrue(VtcRideOverview::canView());
    }

    public function test_le_widget_est_visible_pour_un_utilisateur_lie_a_un_driver(): void
    {
        $user = User::factory()->create();
        $this->makeDriver(['user_id' => $user->id]);

        $this->actingAs($user);

        $this->assertTrue(VtcRideOverview::canView());
    }

    public function test_le_widget_est_invisible_sans_role_ni_driver_associe(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(VtcRideOverview::canView());
    }

    /*
     * =================================================================
     * Ne compte que les courses CONFIRMÉES
     * =================================================================
     */

    public function test_une_course_en_brouillon_nest_comptee_dans_aucune_statistique(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $driver = $this->makeDriver();
        // Brouillon : montants calculés (100 HT), mais status reste
        // 'draft' — ne doit apparaître dans aucun total.
        VtcRide::create([
            'reference' => 'VTC-OV-1',
            'price_ht' => 100,
            'driver_id' => $driver->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(VtcRideOverview::class)
            ->assertSee('0') // Courses confirmées (total) = 0
            ->assertDontSee('110,00 €');
    }

    public function test_une_course_annulee_nest_comptee_dans_aucune_statistique(): void
    {
        $driver = $this->makeDriver();
        // Créée directement au statut 'cancelled' : saving() ne
        // recalcule que pour un statut draft/null, donc total_ttc reste
        // NULL ici — même sans cela, le filtre status=confirmed suffit
        // à l'exclure.
        VtcRide::create([
            'reference' => 'VTC-OV-2',
            'price_ht' => 100,
            'driver_id' => $driver->id,
            'status' => VtcRide::STATUS_CANCELLED,
        ]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(VtcRideOverview::class)
            ->assertSee('0'); // Courses confirmées (total) = 0
    }

    /*
     * =================================================================
     * Agrégats — montants et compteurs corrects
     * =================================================================
     */

    public function test_le_total_confirme_additionne_bien_les_courses_de_plusieurs_chauffeurs_pour_un_manager(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $driverA = $this->makeDriver(['name' => 'Chauffeur A']);
        $driverB = $this->makeDriver(['name' => 'Chauffeur B']);

        // 100 HT + 10% = 110 TTC, et 50 HT + 10% = 55 TTC -> 165 TTC.
        $this->makeConfirmedRide($driverA, 100, 'VTC-OV-3');
        $this->makeConfirmedRide($driverB, 50, 'VTC-OV-4');

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(VtcRideOverview::class)
            ->assertSee('2') // Courses confirmées (total)
            ->assertSee('165,00 €'); // CA TTC (total)
    }

    public function test_un_chauffeur_ne_voit_que_ses_propres_statistiques(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $userA = User::factory()->create();
        $driverA = $this->makeDriver(['name' => 'Chauffeur A', 'user_id' => $userA->id]);
        $driverB = $this->makeDriver(['name' => 'Chauffeur B']);

        // Chauffeur A : 100 HT -> 110 TTC. Chauffeur B : 200 HT -> 220 TTC.
        $this->makeConfirmedRide($driverA, 100, 'VTC-OV-5');
        $this->makeConfirmedRide($driverB, 200, 'VTC-OV-6');

        $this->actingAs($userA);

        Livewire::test(VtcRideOverview::class)
            ->assertSee('1') // Courses confirmées (total) = seulement la sienne
            ->assertSee('110,00 €') // Sa propre CA TTC
            ->assertDontSee('330,00 €') // Jamais le cumul des deux chauffeurs
            ->assertDontSee('220,00 €'); // Jamais le CA du chauffeur B seul
    }

    /*
     * =================================================================
     * Période "ce mois" — basée sur confirmed_at
     * =================================================================
     */

    public function test_une_course_confirmee_le_mois_dernier_nest_pas_comptee_dans_ce_mois_mais_lest_dans_le_total(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $driver = $this->makeDriver();
        $rideOldMonth = $this->makeConfirmedRide($driver, 100, 'VTC-OV-7'); // 110 TTC
        $this->backdateConfirmedAtToLastMonth($rideOldMonth);

        $rideThisMonth = $this->makeConfirmedRide($driver, 50, 'VTC-OV-8'); // 55 TTC

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(VtcRideOverview::class)
            // Total : les deux courses -> 2 courses, 165,00 €.
            ->assertSee('2')
            ->assertSee('165,00 €')
            // Ce mois : seulement la course du mois en cours -> 55,00 €.
            ->assertSee('55,00 €');
    }

    /*
     * =================================================================
     * Étape 5.12b : tuiles fixes "mois dernier" / "cette année"
     * =================================================================
     */

    public function test_la_tuile_mois_dernier_ninclut_que_le_mois_calendaire_precedent(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $driver = $this->makeDriver();

        // Mois dernier (M-1) : 50 HT -> 55,00 € TTC. Seule course
        // attendue dans la tuile "mois dernier".
        $rideLastMonth = $this->makeConfirmedRide($driver, 50, 'VTC-OV-9');
        $this->backdateConfirmedAtTo($rideLastMonth, now()->subMonthNoOverflow()->startOfMonth()->addDay());

        // Il y a deux mois (M-2) : 30 HT -> 33,00 € TTC. Ne doit PAS
        // apparaître dans "mois dernier".
        $rideTwoMonthsAgo = $this->makeConfirmedRide($driver, 30, 'VTC-OV-10');
        $this->backdateConfirmedAtTo($rideTwoMonthsAgo, now()->subMonthsNoOverflow(2)->startOfMonth()->addDay());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(VtcRideOverview::class)
            ->assertSee('55,00 €')
            ->assertDontSee('33,00 €');
    }

    public function test_la_tuile_cette_annee_exclut_lannee_precedente_mais_inclut_les_mois_recents(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $driver = $this->makeDriver();

        // Ce mois : 100 HT -> 110,00 € TTC.
        $this->makeConfirmedRide($driver, 100, 'VTC-OV-11');

        // Il y a deux mois (M-2), toujours cette année : 30 HT ->
        // 33,00 € TTC.
        $rideTwoMonthsAgo = $this->makeConfirmedRide($driver, 30, 'VTC-OV-12');
        $this->backdateConfirmedAtTo($rideTwoMonthsAgo, now()->subMonthsNoOverflow(2)->startOfMonth()->addDay());

        // Année précédente : 20 HT -> 22,00 € TTC. Ne doit PAS
        // apparaître dans "cette année" (mais doit rester dans le
        // total).
        $rideLastYear = $this->makeConfirmedRide($driver, 20, 'VTC-OV-13');
        $this->backdateConfirmedAtTo($rideLastYear, now()->subYearNoOverflow()->startOfYear()->addDay());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(VtcRideOverview::class)
            // Cette année : 110,00 + 33,00 = 143,00 €.
            ->assertSee('143,00 €')
            // Total : 110,00 + 33,00 + 22,00 = 165,00 €.
            ->assertSee('165,00 €')
            // Le montant isolé de l'année précédente n'apparaît nulle
            // part dans la tuile "cette année".
            ->assertDontSee('22,00 €');
    }

    /**
     * Invariant explicitement demandé (étape 5.12b) : une course
     * confirmée reste dans l'historique statistique même si
     * performed_at est NULL ou si son chauffeur/véhicule devient
     * inactif après coup — déjà garanti par construction
     * (scopedConfirmedRidesQuery() ne filtre jamais sur ces deux
     * champs), vérifié ici explicitement plutôt que supposé.
     */
    public function test_une_course_confirmee_reste_comptee_meme_sans_performed_at_et_avec_chauffeur_devenu_inactif(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $driver = $this->makeDriver(['is_active' => true]);
        $ride = $this->makeConfirmedRide($driver, 100, 'VTC-OV-14'); // 110,00 € TTC

        $this->assertNull($ride->performed_at);

        $driver->update(['is_active' => false]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(VtcRideOverview::class)
            ->assertSee('1') // Courses confirmées (total)
            ->assertSee('110,00 €'); // CA TTC (total)
    }
}
