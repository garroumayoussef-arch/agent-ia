<?php

namespace Tests\Feature;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier "facturation légale VTC" (D11, validé) — le suivi de
 * paiement (InvoicePayment) est INCLUS en V1 : réutilisation stricte,
 * aucune modification de InvoicePayment/HasInvoiceWorkflowActions.
 * Preuve directe que ça fonctionne réellement sur une facture VTC, au
 * niveau modèle ET au niveau de l'action Filament déjà existante
 * (recordPaymentAction(), T31, non modifiée).
 */
class VtcRideInvoicePaymentTest extends TestCase
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

    private function makeVtcInvoice(float $priceHt = 100): Invoice
    {
        $ride = VtcRide::create([
            'reference' => 'VTC-'.uniqid(),
            'customer_id' => Customer::create([
                'name' => 'Client VTC Paiement',
                'customer_type' => Customer::TYPE_INDIVIDUAL,
                'address' => 'Adresse',
                'city' => 'Lyon',
                'country' => 'France',
            ])->id,
            'driver_id' => Driver::create(['name' => 'Chauffeur', 'is_active' => true])->id,
            'vehicle_id' => Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ', 'is_active' => true])->id,
            'price_ht' => $priceHt,
        ]);
        $ride->markAsConfirmed();

        return Invoice::generateFromVtcRide($ride->fresh());
    }

    /*
     * =================================================================
     * Niveau modèle — InvoicePayment::recordFor() agnostique de l'origine
     * =================================================================
     */

    public function test_un_paiement_partiel_peut_etre_enregistre_sur_une_facture_vtc(): void
    {
        $invoice = $this->makeVtcInvoice(100); // 110 € TTC

        InvoicePayment::recordFor($invoice, 50, now()->toDateString());

        $this->assertEqualsWithDelta(50.0, $invoice->fresh()->amountPaid(), 0.001);
        $this->assertEqualsWithDelta(60.0, $invoice->fresh()->amountRemaining(), 0.001);
        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());
    }

    public function test_un_paiement_total_solde_une_facture_vtc(): void
    {
        $invoice = $this->makeVtcInvoice(100); // 110 € TTC

        InvoicePayment::recordFor($invoice, 110, now()->toDateString());

        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $invoice->fresh()->paymentStatus());
        $this->assertEqualsWithDelta(0.0, $invoice->fresh()->amountRemaining(), 0.001);
    }

    public function test_un_paiement_ne_peut_pas_depasser_le_solde_dune_facture_vtc(): void
    {
        $invoice = $this->makeVtcInvoice(100); // 110 € TTC

        $this->expectException(\Exception::class);

        try {
            InvoicePayment::recordFor($invoice, 200, now()->toDateString());
        } finally {
            $this->assertSame(0, InvoicePayment::count());
        }
    }

    /**
     * D5 (chantier "réconciliation avoirs", non modifié) — le plafond de
     * paiement tient compte des avoirs déjà émis, exactement comme pour
     * une facture de vente : preuve que ce mécanisme, déjà générique,
     * fonctionne aussi sur une facture VTC sans aucune adaptation.
     */
    public function test_le_plafond_de_paiement_tient_compte_dun_avoir_sur_facture_vtc(): void
    {
        $invoice = $this->makeVtcInvoice(100); // 110 € TTC
        $lineIds = CreditNote::creditableLinesFor($invoice)->pluck('id')->all();
        CreditNote::generateFromInvoice($invoice, $lineIds, 'Course contestée', CreditNote::SETTLEMENT_REFUND);

        // Facture intégralement créditée (110 € d'avoir) : aucun paiement
        // ne doit plus pouvoir être accepté, même 1 centime.
        $this->expectException(\Exception::class);
        InvoicePayment::recordFor($invoice->fresh(), 1, now()->toDateString());
    }

    /*
     * =================================================================
     * Niveau UI — recordPaymentAction() (T31, non modifiée) déjà
     * disponible sur une facture VTC via ViewInvoice
     * =================================================================
     */

    public function test_laction_enregistrer_un_paiement_est_visible_sur_une_facture_vtc(): void
    {
        $invoice = $this->makeVtcInvoice();
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('recordPayment');
    }

    public function test_appeler_laction_enregistre_effectivement_un_paiement_sur_une_facture_vtc(): void
    {
        $invoice = $this->makeVtcInvoice(); // 110 € TTC
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('recordPayment', data: ['amount' => 110, 'paid_at' => now()->toDateString()]);

        $this->assertSame(1, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $invoice->fresh()->paymentStatus());
    }
}
