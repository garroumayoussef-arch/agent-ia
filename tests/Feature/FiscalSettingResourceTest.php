<?php

namespace Tests\Feature;

use App\Filament\Resources\FiscalSettings\FiscalSettingResource;
use App\Filament\Resources\FiscalSettings\Pages\EditFiscalSetting;
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
 * Étape 5.4b : administration du régime fiscal (FiscalSetting).
 * Réservée aux admins (y compris en lecture), contrairement à
 * TaxRateResource — cf. FiscalSettingResource pour la justification.
 */
class FiscalSettingResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeVtcFiscalSetting(?TaxRate $taxRate = null): FiscalSetting
    {
        return FiscalSetting::create([
            'activity' => FiscalSetting::ACTIVITY_VTC,
            'label' => 'Transport de voyageurs (VTC)',
            'tax_rate_id' => $taxRate?->id,
        ]);
    }

    /*
     * =================================================================
     * Permissions : réservé aux admins, y compris en lecture
     * =================================================================
     */

    public function test_un_manager_ne_peut_pas_consulter_les_regimes_fiscaux(): void
    {
        $this->makeVtcFiscalSetting();
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->get(FiscalSettingResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_viewer_ne_peut_pas_consulter_les_regimes_fiscaux(): void
    {
        $this->makeVtcFiscalSetting();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->get(FiscalSettingResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_utilisateur_sans_role_ne_peut_pas_consulter_les_regimes_fiscaux(): void
    {
        $this->makeVtcFiscalSetting();
        $this->actingAs(User::factory()->create());

        $this->get(FiscalSettingResource::getUrl('index'))->assertForbidden();
    }

    public function test_un_admin_peut_consulter_et_modifier_les_regimes_fiscaux(): void
    {
        $setting = $this->makeVtcFiscalSetting();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->get(FiscalSettingResource::getUrl('index'))->assertSuccessful();
        $this->get(FiscalSettingResource::getUrl('edit', ['record' => $setting]))->assertSuccessful();
    }

    /*
     * =================================================================
     * Affichage : jamais NULL/exonéré confondu avec 0 %
     * =================================================================
     */

    public function test_affichage_dun_regime_non_configure(): void
    {
        $this->makeVtcFiscalSetting(null);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->get(FiscalSettingResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSeeText('Non configuré')
            ->assertDontSeeText('0 %');
    }

    public function test_affichage_dun_regime_taxable(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true]);
        $this->makeVtcFiscalSetting($rate10);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->get(FiscalSettingResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSeeText('Taxable (10.00 %)');
    }

    public function test_affichage_dun_regime_exonere(): void
    {
        $exempt = TaxRate::create([
            'label' => 'Franchise en base',
            'type' => TaxRate::TYPE_EXEMPT,
            'legal_mention' => 'TVA non applicable',
            'is_active' => true,
        ]);
        $this->makeVtcFiscalSetting($exempt);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->get(FiscalSettingResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSeeText('Exonérée / non facturée');
    }

    /*
     * =================================================================
     * Modification via le formulaire
     * =================================================================
     */

    public function test_un_admin_peut_configurer_le_taux_vtc_via_le_formulaire(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true]);
        $setting = $this->makeVtcFiscalSetting(null);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(EditFiscalSetting::class, ['record' => $setting->getKey()])
            ->fillForm(['tax_rate_id' => $rate10->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($rate10->id, $setting->fresh()->tax_rate_id);
    }

    public function test_le_champ_activity_nest_pas_modifiable(): void
    {
        $setting = $this->makeVtcFiscalSetting();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(EditFiscalSetting::class, ['record' => $setting->getKey()])
            ->assertFormFieldIsDisabled('activity');
    }

    /*
     * =================================================================
     * Gel fiscal : reconfigurer le régime ne modifie jamais une course
     * déjà confirmée ; une nouvelle course reflète le nouveau régime
     * =================================================================
     */

    public function test_reconfigurer_le_regime_ne_modifie_pas_une_course_deja_confirmee(): void
    {
        $rate10 = TaxRate::create(['label' => 'VTC 10%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true]);
        $exempt = TaxRate::create(['label' => 'Franchise', 'type' => TaxRate::TYPE_EXEMPT, 'is_active' => true]);
        $setting = $this->makeVtcFiscalSetting($rate10);

        $ride = VtcRide::create([
            'reference' => 'VTC-FS-1',
            'price_ht' => 100,
            'driver_id' => Driver::create(['name' => 'Chauffeur Test'])->id,
            'vehicle_id' => Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ'])->id,
        ]);
        $ride->markAsConfirmed();
        $this->assertSame('10.00', $ride->tax_amount);

        // Un admin bascule le régime vers l'exonération APRÈS
        // confirmation de la course.
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        Livewire::test(EditFiscalSetting::class, ['record' => $setting->getKey()])
            ->fillForm(['tax_rate_id' => $exempt->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $ride->refresh();
        $this->assertSame(VtcRide::TAX_STATUS_TAXABLE, $ride->tax_status);
        $this->assertSame('10.00', $ride->tax_amount);
        $this->assertSame('110.00', $ride->total_ttc);

        // Une NOUVELLE course, elle, reflète bien le nouveau régime.
        $newRide = VtcRide::create(['reference' => 'VTC-FS-2', 'price_ht' => 100]);
        $this->assertSame(VtcRide::TAX_STATUS_EXEMPT, $newRide->tax_status);
        $this->assertSame('0.00', $newRide->tax_amount);

        $this->assertSame(0, StockMovement::count());
    }
}
