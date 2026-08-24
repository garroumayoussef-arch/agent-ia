<?php

namespace Tests\Feature;

use App\Filament\Resources\Drivers\DriverResource;
use App\Filament\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Filament\Resources\VtcRides\Pages\CreateVtcRide;
use App\Filament\Resources\VtcRides\Pages\EditVtcRide;
use App\Filament\Resources\VtcRides\VtcRideResource;
use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape 5.3 : Resources Filament VTC. Aucune règle fiscale ni aucun
 * calcul n'est testé ici en tant que tel (déjà couvert par
 * VtcRideTest.php) — ces tests vérifient que l'INTERFACE expose
 * correctement ce que VtcRide a déjà calculé, sans jamais recalculer
 * ni permettre de contourner ses protections.
 */
class VtcRideResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('manager'));
    }

    private function makeDriver(array $attributes = []): Driver
    {
        return Driver::create(array_merge(['name' => 'Chauffeur Test'], $attributes));
    }

    private function makeVehicle(array $attributes = []): Vehicle
    {
        return Vehicle::create(array_merge(['plate_number' => 'AA-'.uniqid().'-ZZ'], $attributes));
    }

    private function setVtcFiscalSetting(?TaxRate $taxRate): FiscalSetting
    {
        return FiscalSetting::updateOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            ['tax_rate_id' => $taxRate?->id],
        );
    }

    /*
     * =================================================================
     * Accessibilité des pages
     * =================================================================
     */

    public function test_les_pages_des_ressources_vtc_sont_accessibles(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-UI-1']);

        $this->get(VtcRideResource::getUrl('index'))->assertSuccessful();
        $this->get(VtcRideResource::getUrl('create'))->assertSuccessful();
        $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))->assertSuccessful();
        $this->get(VtcRideResource::getUrl('edit', ['record' => $ride]))->assertSuccessful();

        $this->get(DriverResource::getUrl('index'))->assertSuccessful();
        $this->get(DriverResource::getUrl('create'))->assertSuccessful();

        $this->get(VehicleResource::getUrl('index'))->assertSuccessful();
        $this->get(VehicleResource::getUrl('create'))->assertSuccessful();
    }

    /*
     * =================================================================
     * Brouillon : driver/vehicle nullables
     * =================================================================
     */

    public function test_creer_une_course_en_brouillon_sans_driver_ni_vehicle_via_le_formulaire(): void
    {
        Livewire::test(CreateVtcRide::class)
            ->fillForm([
                'reference' => 'VTC-UI-2',
                'price_ht' => 100,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ride = VtcRide::where('reference', 'VTC-UI-2')->firstOrFail();

        $this->assertSame(VtcRide::STATUS_DRAFT, $ride->status);
        $this->assertNull($ride->driver_id);
        $this->assertNull($ride->vehicle_id);
    }

    public function test_creer_un_driver_sans_compte_utilisateur_via_le_formulaire(): void
    {
        Livewire::test(CreateDriver::class)
            ->fillForm(['name' => 'Chauffeur UI'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('drivers', ['name' => 'Chauffeur UI', 'user_id' => null]);
    }

    public function test_creer_un_vehicle_via_le_formulaire(): void
    {
        Livewire::test(CreateVehicle::class)
            ->fillForm(['plate_number' => 'BB-111-CC'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('vehicles', ['plate_number' => 'BB-111-CC']);
    }

    /*
     * =================================================================
     * Affichage HT/TVA/TTC — jamais de recalcul, jamais NULL affiché
     * comme 0 €
     * =================================================================
     */

    public function test_affichage_dune_course_taxable_a_10_pourcent(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create(['reference' => 'VTC-UI-3', 'price_ht' => 100]);

        $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))
            ->assertSuccessful()
            ->assertSeeText('100,00 €') // montant HT
            ->assertSeeText('10.00 %') // taux appliqué
            ->assertSeeText('10,00 €') // TVA
            ->assertSeeText('110,00 €') // total TTC
            ->assertSeeText('Taxable');
    }

    public function test_affichage_dune_course_exoneree(): void
    {
        $exempt = TaxRate::create([
            'label' => 'Franchise en base',
            'type' => TaxRate::TYPE_EXEMPT,
            'legal_mention' => 'TVA non applicable, article 293 B du CGI',
        ]);
        $this->setVtcFiscalSetting($exempt);

        $ride = VtcRide::create(['reference' => 'VTC-UI-4', 'price_ht' => 100]);

        $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))
            ->assertSuccessful()
            ->assertSeeText('Exonérée / non facturée')
            ->assertSeeText('0,00 € (exonérée)')
            ->assertSeeText('TVA non applicable, article 293 B du CGI');
    }

    public function test_affichage_dune_tva_non_resolue_najamais_zero(): void
    {
        // Aucun régime VTC configuré.
        $ride = VtcRide::create(['reference' => 'VTC-UI-5', 'price_ht' => 100]);

        $response = $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))
            ->assertSuccessful()
            ->assertSeeText('Non résolue')
            ->assertSeeText('TVA non résolue');

        // Jamais l'un des deux libellés "connus" (0,00 € exonérée, ou
        // un montant en euros) pour cette TVA précisément non résolue.
        $response->assertDontSeeText('0,00 € (exonérée)');
    }

    public function test_affichage_driver_et_vehicle_dans_la_liste(): void
    {
        $driver = $this->makeDriver(['name' => 'Chauffeur Liste']);
        $vehicle = $this->makeVehicle(['plate_number' => 'ZZ-999-AA']);

        VtcRide::create([
            'reference' => 'VTC-UI-6',
            'price_ht' => 50,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $this->get(VtcRideResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSeeText('Chauffeur Liste')
            ->assertSeeText('ZZ-999-AA');
    }

    /*
     * =================================================================
     * Confirmation : délègue à VtcRide::markAsConfirmed(), aucune
     * logique dupliquée dans Filament
     * =================================================================
     */

    public function test_confirmer_une_course_valide_via_laction(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-UI-7',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->callAction('confirmRide');

        $this->assertSame(VtcRide::STATUS_CONFIRMED, $ride->fresh()->status);
    }

    public function test_confirmation_refusee_sans_driver_via_laction(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-UI-8',
            'price_ht' => 100,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->callAction('confirmRide');

        // L'action a échoué proprement (notification), pas d'exception
        // remontée jusqu'au test, et la course reste en brouillon.
        $this->assertSame(VtcRide::STATUS_DRAFT, $ride->fresh()->status);
    }

    public function test_confirmation_refusee_sans_vehicle_via_laction(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-UI-9',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
        ]);

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->callAction('confirmRide');

        $this->assertSame(VtcRide::STATUS_DRAFT, $ride->fresh()->status);
    }

    public function test_laction_confirmer_nest_plus_visible_apres_confirmation(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-UI-10',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionHidden('confirmRide');
    }

    /*
     * =================================================================
     * Annulation (étape 5.7) : délègue à VtcRide::cancel(), uniquement
     * depuis brouillon, aucune logique dupliquée dans Filament
     * =================================================================
     */

    public function test_annuler_une_course_en_brouillon_valide_via_laction(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-UI-12']);

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->callAction('cancelRide');

        $this->assertSame(VtcRide::STATUS_CANCELLED, $ride->fresh()->status);
    }

    public function test_laction_annuler_est_visible_pour_une_course_en_brouillon(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-UI-13']);

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionVisible('cancelRide');
    }

    public function test_laction_annuler_nest_plus_visible_apres_confirmation(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-UI-14',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionHidden('cancelRide');
    }

    /*
     * =================================================================
     * Reçu récapitulatif (étape 5.9) : action visible uniquement pour
     * une course confirmée — le contenu/l'autorisation de la route
     * elle-même sont couverts par VtcRideReceiptTest.php
     * =================================================================
     */

    public function test_laction_recu_nest_pas_visible_pour_une_course_en_brouillon(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-UI-15']);

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionHidden('receipt');
    }

    public function test_laction_recu_devient_visible_apres_confirmation(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-UI-16',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionVisible('receipt');
    }

    public function test_laction_recu_nest_plus_visible_apres_annulation(): void
    {
        $ride = VtcRide::create(['reference' => 'VTC-UI-17']);
        $ride->cancel();

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionHidden('receipt');
    }

    /*
     * =================================================================
     * Protection des champs financiers / historique après confirmation
     * =================================================================
     */

    public function test_modifier_le_prix_ht_apres_confirmation_via_le_formulaire_ne_change_rien(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-UI-11',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        // price_ht est désactivé (disabled()) dès que la course n'est
        // plus en brouillon : un champ désactivé n'est pas soumis par
        // Filament, donc la sauvegarde ne doit rien changer, sans
        // exception remontant jusqu'à l'utilisateur.
        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->fillForm(['price_ht' => 999])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('100.00', $ride->fresh()->price_ht);
        $this->assertSame('10.00', $ride->fresh()->tax_amount);
    }

    public function test_lhistorique_fiscal_affiche_reste_celui_fige_apres_changement_du_taux(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-UI-12',
            'price_ht' => 100,
            'driver_id' => $this->makeDriver()->id,
            'vehicle_id' => $this->makeVehicle()->id,
        ]);
        $ride->markAsConfirmed();

        // Le taux de référence change APRÈS confirmation.
        $rate10->update(['rate' => 20]);

        // La vue continue d'afficher les valeurs historiques figées
        // (10 %, 10,00 €, 110,00 €), jamais recalculées depuis le
        // nouveau taux (20 %).
        $this->get(VtcRideResource::getUrl('view', ['record' => $ride]))
            ->assertSuccessful()
            ->assertSeeText('10.00 %')
            ->assertSeeText('10,00 €')
            ->assertSeeText('110,00 €')
            ->assertDontSeeText('20,00 €')
            ->assertDontSeeText('120,00 €');
    }

    /*
     * =================================================================
     * Aucune interaction avec le stock
     * =================================================================
     */

    public function test_creer_et_confirmer_une_course_depuis_la_resource_ninteragit_jamais_avec_le_stock(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);
        $this->setVtcFiscalSetting($rate10);

        Livewire::test(CreateVtcRide::class)
            ->fillForm([
                'reference' => 'VTC-UI-13',
                'price_ht' => 100,
                'driver_id' => $this->makeDriver()->id,
                'vehicle_id' => $this->makeVehicle()->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ride = VtcRide::where('reference', 'VTC-UI-13')->firstOrFail();

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->callAction('confirmRide');

        $this->assertSame(VtcRide::STATUS_CONFIRMED, $ride->fresh()->status);
        $this->assertSame(0, StockMovement::count());
    }
}
