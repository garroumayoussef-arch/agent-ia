<?php

namespace Tests\Feature;

use App\Filament\Resources\VtcRides\VtcRideResource;
use App\Models\Driver;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape 5.5 : restriction d'accès aux courses VTC par propriétaire
 * (chauffeur), en plus du rôle. Aucune règle fiscale n'est concernée —
 * ces tests portent uniquement sur QUI peut voir/modifier quoi.
 */
class VtcRideAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ']);
    }

    /*
     * =================================================================
     * Un chauffeur ne voit que ses propres courses
     * =================================================================
     */

    public function test_un_chauffeur_ne_voit_que_ses_propres_courses_dans_la_liste(): void
    {
        $userA = User::factory()->create();
        $driverA = Driver::create(['name' => 'Chauffeur A', 'user_id' => $userA->id]);
        $driverB = Driver::create(['name' => 'Chauffeur B']);

        $rideA = VtcRide::create(['reference' => 'VTC-AUTH-1', 'driver_id' => $driverA->id]);
        $rideB = VtcRide::create(['reference' => 'VTC-AUTH-2', 'driver_id' => $driverB->id]);

        $this->actingAs($userA);

        $response = $this->get(VtcRideResource::getUrl('index'))->assertSuccessful();
        $response->assertSeeText('VTC-AUTH-1');
        $response->assertDontSeeText('VTC-AUTH-2');
    }

    public function test_un_chauffeur_ne_peut_pas_consulter_la_course_dun_autre_chauffeur_par_url_directe(): void
    {
        $userA = User::factory()->create();
        Driver::create(['name' => 'Chauffeur A', 'user_id' => $userA->id]);
        $driverB = Driver::create(['name' => 'Chauffeur B']);

        $rideB = VtcRide::create(['reference' => 'VTC-AUTH-3', 'driver_id' => $driverB->id]);

        $this->actingAs($userA);

        // getEloquentQuery() scope la requête de résolution de route
        // elle-même : la course d'un autre chauffeur est introuvable
        // (404), pas "trouvée puis refusée" (403) — elle n'existe tout
        // simplement pas dans le périmètre visible de ce chauffeur.
        $this->get(VtcRideResource::getUrl('view', ['record' => $rideB]))->assertNotFound();
        $this->get(VtcRideResource::getUrl('edit', ['record' => $rideB]))->assertNotFound();
    }

    public function test_un_chauffeur_peut_consulter_sa_propre_course(): void
    {
        $userA = User::factory()->create();
        $driverA = Driver::create(['name' => 'Chauffeur A', 'user_id' => $userA->id]);

        $ride = VtcRide::create(['reference' => 'VTC-AUTH-4', 'driver_id' => $driverA->id]);

        $this->actingAs($userA);

        $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))->assertSuccessful();
    }

    /*
     * =================================================================
     * Admin/manager conservent l'accès complet
     * =================================================================
     */

    public function test_un_manager_voit_toutes_les_courses_de_tous_les_chauffeurs(): void
    {
        $driverA = Driver::create(['name' => 'Chauffeur A']);
        $driverB = Driver::create(['name' => 'Chauffeur B']);

        VtcRide::create(['reference' => 'VTC-AUTH-5', 'driver_id' => $driverA->id]);
        VtcRide::create(['reference' => 'VTC-AUTH-6', 'driver_id' => $driverB->id]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $response = $this->get(VtcRideResource::getUrl('index'))->assertSuccessful();
        $response->assertSeeText('VTC-AUTH-5');
        $response->assertSeeText('VTC-AUTH-6');
    }

    public function test_un_admin_peut_consulter_la_course_de_nimporte_quel_chauffeur(): void
    {
        $driver = Driver::create(['name' => 'Chauffeur A']);
        $ride = VtcRide::create(['reference' => 'VTC-AUTH-7', 'driver_id' => $driver->id]);

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))->assertSuccessful();
    }

    /*
     * =================================================================
     * Aucun Driver associé = aucun accès
     * =================================================================
     */

    public function test_un_utilisateur_sans_driver_associe_na_aucun_acces(): void
    {
        $driver = Driver::create(['name' => 'Chauffeur A']);
        $ride = VtcRide::create(['reference' => 'VTC-AUTH-8', 'driver_id' => $driver->id]);

        $this->actingAs(User::factory()->create()); // aucun rôle, aucun Driver lié

        $this->get(VtcRideResource::getUrl('index'))->assertForbidden();
        // Même raison qu'au-dessus : requête scopée à "aucune course"
        // pour cet utilisateur, donc 404 plutôt que 403 sur un accès
        // direct par URL.
        $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))->assertNotFound();
    }

    /*
     * =================================================================
     * Un chauffeur ne modifie ses courses que tant qu'elles sont en
     * brouillon
     * =================================================================
     */

    public function test_un_chauffeur_peut_modifier_sa_propre_course_en_brouillon(): void
    {
        $userA = User::factory()->create();
        $driverA = Driver::create(['name' => 'Chauffeur A', 'user_id' => $userA->id]);
        $ride = VtcRide::create(['reference' => 'VTC-AUTH-9', 'driver_id' => $driverA->id]);

        $this->actingAs($userA);

        $this->get(VtcRideResource::getUrl('edit', ['record' => $ride]))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_plus_modifier_sa_course_une_fois_confirmee(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        \App\Models\FiscalSetting::create([
            'activity' => \App\Models\FiscalSetting::ACTIVITY_VTC,
            'tax_rate_id' => $rate10->id,
        ]);

        $userA = User::factory()->create();
        $driverA = Driver::create(['name' => 'Chauffeur A', 'user_id' => $userA->id]);
        $ride = VtcRide::create([
            'reference' => 'VTC-AUTH-10',
            'price_ht' => 100,
            'driver_id' => $driverA->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        $this->actingAs($userA);

        // Il peut toujours CONSULTER sa course confirmée...
        $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))->assertSuccessful();
        // ...mais plus la MODIFIER.
        $this->get(VtcRideResource::getUrl('edit', ['record' => $ride]))->assertForbidden();
    }

    /**
     * Même en forçant l'appel Livewire directement (contournement de
     * l'UI), les montants financiers restent protégés par le modèle
     * lui-même — défense en profondeur, indépendante de
     * l'autorisation Filament ci-dessus.
     */
    public function test_aucun_changement_de_montant_ni_de_taux_napres_confirmation_meme_via_le_modele(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        \App\Models\FiscalSetting::create([
            'activity' => \App\Models\FiscalSetting::ACTIVITY_VTC,
            'tax_rate_id' => $rate10->id,
        ]);

        $driver = Driver::create(['name' => 'Chauffeur A']);
        $ride = VtcRide::create([
            'reference' => 'VTC-AUTH-11',
            'price_ht' => 100,
            'driver_id' => $driver->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        $this->expectException(\Exception::class);
        $ride->update(['tax_amount' => 999, 'total_ttc' => 999, 'tax_rate' => 99]);
    }

    public function test_aucune_interaction_stockmovement_dans_ce_scenario_de_restriction_dacces(): void
    {
        $userA = User::factory()->create();
        $driverA = Driver::create(['name' => 'Chauffeur A', 'user_id' => $userA->id]);
        VtcRide::create(['reference' => 'VTC-AUTH-12', 'driver_id' => $driverA->id]);

        $this->assertSame(0, \App\Models\StockMovement::count());
    }
}
