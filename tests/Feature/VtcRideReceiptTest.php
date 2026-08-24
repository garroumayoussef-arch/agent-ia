<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 5.9 : reçu récapitulatif imprimable d'une course VTC confirmée
 * (PAS une facture légale — cf. la vue). Route HTTP classique, hors du
 * panel Filament : l'autorisation est vérifiée dans
 * VtcRideReceiptController, en réutilisant VtcRideResource::canView()
 * telle quelle (même règle que l'étape 5.5/5.8, jamais redérivée ici).
 *
 * Aucune règle fiscale ni de calcul n'est retestée ici (déjà couverte
 * par VtcRideTest.php) — ces tests portent sur l'accès à la route et
 * sur la restitution fidèle des valeurs déjà calculées.
 */
class VtcRideReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeDriver(array $attributes = []): Driver
    {
        return Driver::create(array_merge(['name' => 'Chauffeur Test'], $attributes));
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ']);
    }

    private function setVtcFiscalSetting(?TaxRate $taxRate): FiscalSetting
    {
        return FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => $taxRate?->id],
        );
    }

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

    /*
     * =================================================================
     * Uniquement pour une course confirmée
     * =================================================================
     */

    public function test_le_recu_est_accessible_pour_une_course_confirmee_par_un_manager(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = $this->makeConfirmedRide($this->makeDriver(), 100, 'VTC-REC-1');

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(route('vtc-rides.receipt', $ride))->assertSuccessful();
    }

    public function test_le_recu_est_refuse_pour_une_course_en_brouillon(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-REC-2']);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(route('vtc-rides.receipt', $ride))->assertForbidden();
    }

    public function test_le_recu_est_refuse_pour_une_course_annulee(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-REC-3']);
        $ride->cancel();

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(route('vtc-rides.receipt', $ride))->assertForbidden();
    }

    /*
     * =================================================================
     * Autorisation : admin/manager (tout), chauffeur (SES courses),
     * aucun accès sinon — même règle que VtcRideResource
     * =================================================================
     */

    public function test_le_recu_est_accessible_pour_une_course_confirmee_par_un_admin(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = $this->makeConfirmedRide($this->makeDriver(), 100, 'VTC-REC-4');

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->get(route('vtc-rides.receipt', $ride))->assertSuccessful();
    }

    public function test_un_chauffeur_peut_consulter_le_recu_de_sa_propre_course_confirmee(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $userA = User::factory()->create();
        $driverA = $this->makeDriver(['name' => 'Chauffeur A', 'user_id' => $userA->id]);
        $ride = $this->makeConfirmedRide($driverA, 100, 'VTC-REC-5');

        $this->actingAs($userA);

        $this->get(route('vtc-rides.receipt', $ride))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_pas_consulter_le_recu_de_la_course_dun_autre_chauffeur(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $userA = User::factory()->create();
        $this->makeDriver(['name' => 'Chauffeur A', 'user_id' => $userA->id]);
        $driverB = $this->makeDriver(['name' => 'Chauffeur B']);
        $rideB = $this->makeConfirmedRide($driverB, 100, 'VTC-REC-6');

        $this->actingAs($userA);

        // Même convention que l'accès direct par URL à VtcRideResource
        // (5.5) : la course d'un autre chauffeur n'existe pas dans son
        // périmètre -> 404, pas 403.
        $this->get(route('vtc-rides.receipt', $rideB))->assertNotFound();
    }

    public function test_un_utilisateur_sans_role_ni_driver_associe_na_aucun_acces_au_recu(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = $this->makeConfirmedRide($this->makeDriver(), 100, 'VTC-REC-7');

        $this->actingAs(User::factory()->create()); // aucun rôle, aucun Driver lié

        $this->get(route('vtc-rides.receipt', $ride))->assertNotFound();
    }

    /*
     * =================================================================
     * Contenu : restitution fidèle des valeurs déjà calculées, aucun
     * recalcul
     * =================================================================
     */

    public function test_le_recu_affiche_la_reference_et_le_total_ttc(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = $this->makeConfirmedRide($this->makeDriver(), 100, 'VTC-REC-8');

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        // 100 HT + 10 % = 110,00 € TTC.
        $this->get(route('vtc-rides.receipt', $ride))
            ->assertSuccessful()
            ->assertSeeText('VTC-REC-8')
            ->assertSeeText('110,00 €');
    }

    public function test_le_recu_affiche_la_mention_legale_pour_une_course_exoneree(): void
    {
        $exempt = TaxRate::create([
            'label' => 'Franchise en base',
            'type' => TaxRate::TYPE_EXEMPT,
            'legal_mention' => 'TVA non applicable, art. 293 B du CGI',
        ]);
        $this->setVtcFiscalSetting($exempt);

        $ride = $this->makeConfirmedRide($this->makeDriver(), 100, 'VTC-REC-9');

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(route('vtc-rides.receipt', $ride))
            ->assertSuccessful()
            ->assertSeeText('TVA non applicable, art. 293 B du CGI');
    }

    public function test_le_recu_precise_quil_nest_pas_une_facture_legale(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = $this->makeConfirmedRide($this->makeDriver(), 100, 'VTC-REC-10');

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(route('vtc-rides.receipt', $ride))
            ->assertSuccessful()
            ->assertSeeText('ne constitue pas une facture');
    }
}
