<?php

namespace Tests\Feature;

use App\Filament\Resources\VtcRides\Pages\EditVtcRide;
use App\Filament\Resources\VtcRides\Pages\ViewVtcRide;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\Invoice;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier "facturation légale VTC" (D5/D8/D9, validés) — actions
 * "Générer la facture"/"Télécharger la facture" sur VtcRide. Miroir
 * direct de SalesOrderInvoiceActionTest (T23) : mêmes garanties
 * d'autorisation (garde de rôle explicite, jamais un simple ->visible()),
 * même vérification de contournement direct (T25-B/T26).
 */
class VtcRideInvoiceActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        CompanySettings::current()->update([
            'legal_name' => 'Magarrou',
            'legal_form' => 'SASU',
            'address' => '1 rue du Sport',
            'postal_code' => '75000',
            'city' => 'Paris',
            'country' => 'France',
            'siren' => '111222333',
            'vat_regime' => CompanySettings::VAT_REGIME_STANDARD,
            'vat_number' => 'FR11111222333',
            'vtc_invoice_number_prefix' => 'FV',
        ]);

        $rate = TaxRate::create(['label' => 'VTC 10%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true]);
        FiscalSetting::updateOrCreate(['activity' => FiscalSetting::ACTIVITY_VTC], ['tax_rate_id' => $rate->id]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Client VTC',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
    }

    private function makeConfirmedRide(): VtcRide
    {
        $ride = VtcRide::create([
            'reference' => 'VTC-'.uniqid(),
            'customer_id' => $this->makeCustomer()->id,
            'driver_id' => Driver::create(['name' => 'Chauffeur', 'is_active' => true])->id,
            'vehicle_id' => Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ', 'is_active' => true])->id,
            'price_ht' => 100,
        ]);
        $ride->markAsConfirmed();

        return $ride->fresh();
    }

    public function test_laction_est_invisible_sur_une_course_en_brouillon(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $ride = VtcRide::create(['reference' => 'VTC-DRAFT', 'customer_id' => $this->makeCustomer()->id]);

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionHidden('generateInvoice');
    }

    public function test_laction_est_visible_pour_un_manager_sur_une_course_confirmee(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $ride = $this->makeConfirmedRide();

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionVisible('generateInvoice');
    }

    /**
     * Particularité de VtcRideResource (T2/5.5, non modifiée par ce
     * chantier) : contrairement à SalesOrderResource, la lecture n'y est
     * PAS ouverte à tout utilisateur authentifié — canViewAny() exige
     * admin/manager OU un Driver associé (ScopesToOwnDriver). Un
     * "viewer" sans Driver n'a donc ICI aucun accès du tout (404), pas
     * seulement l'action masquée : il n'existe pas d'équivalent VtcRide
     * du test "viewer peut consulter mais pas agir" utilisé côté vente.
     * Le scénario réellement pertinent pour cette Resource — un compte
     * qui PEUT consulter une course mais ne doit pas pouvoir la facturer
     * — est le chauffeur propriétaire, couvert ci-dessous.
     */
    public function test_appeler_laction_genere_effectivement_une_facture(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $ride = $this->makeConfirmedRide();

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->callAction('generateInvoice');

        $this->assertSame(1, Invoice::where('vtc_ride_id', $ride->id)->count());
    }

    /**
     * D8 (validé) — le reçu reste visible EN PLUS de la facture, jamais
     * remplacé par elle.
     */
    public function test_laction_generer_disparait_et_telecharger_apparait_une_fois_la_facture_emise_le_recu_reste_visible(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $ride = $this->makeConfirmedRide();
        Invoice::generateFromVtcRide($ride);

        Livewire::test(EditVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionHidden('generateInvoice')
            ->assertActionVisible('downloadInvoice')
            ->assertActionVisible('receipt');
    }

    /**
     * D9 (validé, statu quo) — un chauffeur, même propriétaire de la
     * course, n'a jamais accès à l'action de facturation (garde
     * explicite VtcRideResource::canEdit(), qui autorise pourtant un
     * chauffeur à éditer SES courses en brouillon — mais jamais celle-ci,
     * qui de toute façon n'est visible que sur une course confirmée,
     * hors du périmètre d'édition d'un chauffeur).
     */
    public function test_un_chauffeur_proprietaire_ne_peut_pas_generer_de_facture(): void
    {
        $ride = $this->makeConfirmedRide();

        $chauffeurUser = User::factory()->create();
        Driver::where('id', $ride->driver_id)->update(['user_id' => $chauffeurUser->id]);
        $this->actingAs($chauffeurUser);

        Livewire::test(ViewVtcRide::class, ['record' => $ride->getKey()])
            ->assertActionHidden('generateInvoice')
            ->call('mountAction', 'generateInvoice');

        $this->assertSame(0, Invoice::where('vtc_ride_id', $ride->id)->count());
    }
}
