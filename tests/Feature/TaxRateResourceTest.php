<?php

namespace Tests\Feature;

use App\Filament\Resources\TaxRates\Pages\CreateTaxRate;
use App\Filament\Resources\TaxRates\Pages\EditTaxRate;
use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\TaxRate;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape 5.4a : administration des taux de TVA (TaxRate). Aucune valeur
 * n'est codée en dur ici — ces tests vérifient que le formulaire reste
 * générique (n'importe quel taux, pas seulement 10 %/20 %) et que
 * l'affichage ne confond jamais un taux exonéré (rate NULL) avec 0 %.
 */
class TaxRateResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('manager'));
    }

    public function test_les_pages_de_la_ressource_sont_accessibles(): void
    {
        $taxRate = TaxRate::create(['label' => 'Test', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 15]);

        $this->get(TaxRateResource::getUrl('index'))->assertSuccessful();
        $this->get(TaxRateResource::getUrl('create'))->assertSuccessful();
        $this->get(TaxRateResource::getUrl('edit', ['record' => $taxRate]))->assertSuccessful();
    }

    /**
     * Le formulaire ne contraint aucune valeur particulière : n'importe
     * quel taux (ici 5,5 %, ni 10 % ni 20 %) doit pouvoir être créé.
     */
    public function test_creer_un_taux_pourcentage_quelconque_via_le_formulaire(): void
    {
        Livewire::test(CreateTaxRate::class)
            ->fillForm([
                'label' => 'Taux réduit',
                'type' => TaxRate::TYPE_PERCENTAGE,
                'rate' => 5.5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $taxRate = TaxRate::where('label', 'Taux réduit')->firstOrFail();

        $this->assertSame(TaxRate::TYPE_PERCENTAGE, $taxRate->type);
        $this->assertSame('5.50', $taxRate->rate);
        $this->assertNull($taxRate->legal_mention);
    }

    public function test_creer_un_taux_exonere_via_le_formulaire(): void
    {
        Livewire::test(CreateTaxRate::class)
            ->fillForm([
                'label' => 'Franchise en base',
                'type' => TaxRate::TYPE_EXEMPT,
                'legal_mention' => 'TVA non applicable, article 293 B du CGI',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $taxRate = TaxRate::where('label', 'Franchise en base')->firstOrFail();

        $this->assertSame(TaxRate::TYPE_EXEMPT, $taxRate->type);
        $this->assertNull($taxRate->rate);
        $this->assertSame('TVA non applicable, article 293 B du CGI', $taxRate->legal_mention);
    }

    public function test_le_champ_rate_est_masque_pour_un_taux_exonere(): void
    {
        Livewire::test(CreateTaxRate::class)
            ->fillForm(['type' => TaxRate::TYPE_EXEMPT])
            ->assertFormFieldIsHidden('rate')
            ->assertFormFieldIsVisible('legal_mention');
    }

    public function test_le_champ_legal_mention_est_masque_pour_un_taux_pourcentage(): void
    {
        Livewire::test(CreateTaxRate::class)
            ->fillForm(['type' => TaxRate::TYPE_PERCENTAGE])
            ->assertFormFieldIsVisible('rate')
            ->assertFormFieldIsHidden('legal_mention');
    }

    public function test_laffichage_dun_taux_exonere_najamais_0_pourcent(): void
    {
        TaxRate::create([
            'label' => 'Franchise en base',
            'type' => TaxRate::TYPE_EXEMPT,
            'legal_mention' => 'TVA non applicable',
        ]);

        $this->get(TaxRateResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSeeText('Exonéré')
            ->assertDontSeeText('0 %');
    }

    public function test_modifier_un_taux_existant_via_le_formulaire(): void
    {
        $taxRate = TaxRate::create(['label' => 'VTC', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10]);

        Livewire::test(EditTaxRate::class, ['record' => $taxRate->getKey()])
            ->fillForm(['rate' => 12])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('12.00', $taxRate->fresh()->rate);
    }
}
