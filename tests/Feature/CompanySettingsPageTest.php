<?php

namespace Tests\Feature;

use App\Filament\Pages\CompanySettingsPage;
use App\Models\CompanySettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T23 — page de réglages singleton (identité légale + régime
 * fiscal). Réservée à l'admin, jamais à un manager/viewer : ces
 * réglages déterminent les mentions légales de toutes les factures
 * futures.
 */
class CompanySettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_un_admin_peut_acceder_a_la_page(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->assertTrue(CompanySettingsPage::canAccess());
    }

    public function test_un_manager_ne_peut_pas_acceder_a_la_page(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->assertFalse(CompanySettingsPage::canAccess());
    }

    public function test_un_viewer_ne_peut_pas_acceder_a_la_page(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->assertFalse(CompanySettingsPage::canAccess());
    }

    public function test_enregistrer_les_reglages_persiste_les_donnees(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(CompanySettingsPage::class)
            ->fillForm([
                'legal_name' => 'Magarrou',
                'legal_form' => 'SASU',
                'address' => '1 rue du Sport',
                'postal_code' => '75000',
                'city' => 'Paris',
                'country' => 'France',
                'siren' => '111222333',
                'vat_regime' => CompanySettings::VAT_REGIME_STANDARD,
                'vat_number' => 'FR11111222333',
                'recovery_indemnity_amount' => 40,
                'invoice_number_prefix' => 'FA',
                // Étape T24 — nouveau champ requis, ajouté à la même
                // page (préfixe de numérotation des avoirs).
                'credit_note_number_prefix' => 'AV',
                // Chantier "facturation légale VTC" — nouveau champ
                // requis, ajouté à la même page (préfixe de la série
                // dédiée aux factures VTC).
                'vtc_invoice_number_prefix' => 'FV',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = CompanySettings::current();
        $this->assertSame('Magarrou', $settings->legal_name);
        $this->assertSame('111222333', $settings->siren);
        $this->assertSame(CompanySettings::VAT_REGIME_STANDARD, $settings->vat_regime);
    }

    public function test_il_nexiste_toujours_quune_seule_ligne_de_configuration(): void
    {
        CompanySettings::current();
        CompanySettings::current();
        CompanySettings::current();

        $this->assertSame(1, CompanySettings::count());
    }
}
