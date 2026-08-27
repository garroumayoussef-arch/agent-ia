<?php

namespace Tests\Feature;

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
use Tests\TestCase;

/**
 * Chantier "facturation légale VTC" (D7, validé) — le PDF d'une facture
 * VTC réutilise strictement InvoicePdfController/la vue invoices.pdf
 * (T23, non modifié), agnostique de l'origine de la facture. Miroir
 * réduit d'InvoicePdfControllerTest, pour une facture d'origine VTC.
 */
class VtcRideInvoicePdfControllerTest extends TestCase
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

    private function makeVtcInvoice(): Invoice
    {
        $ride = VtcRide::create([
            'reference' => 'VTC-PDF',
            'customer_id' => Customer::create([
                'name' => 'Client PDF VTC',
                'customer_type' => Customer::TYPE_INDIVIDUAL,
                'address' => 'Adresse',
                'city' => 'Lyon',
                'country' => 'France',
            ])->id,
            'driver_id' => Driver::create(['name' => 'Chauffeur', 'is_active' => true])->id,
            'vehicle_id' => Vehicle::create(['plate_number' => 'AA-1-ZZ', 'is_active' => true])->id,
            'price_ht' => 100,
        ]);
        $ride->markAsConfirmed();

        return Invoice::generateFromVtcRide($ride->fresh());
    }

    public function test_le_pdf_est_genere_avec_succes_pour_une_facture_vtc(): void
    {
        $invoice = $this->makeVtcInvoice();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $response = $this->get(route('invoices.pdf', $invoice));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    }

    /**
     * D4/D7 (validés) — preuve indirecte que originLabel() est bien
     * invoqué dans le template sans erreur de rendu ni de propriété
     * manquante (sales_order_reference NULL sur une facture VTC), et
     * que le PDF reste régénérable à volonté (jamais un recalcul depuis
     * VtcRide, qui pourrait échouer ou diverger — mêmes garanties que
     * pour une facture de vente, cf. InvoicePdfControllerTest). L'ID
     * interne dompdf variant à chaque rendu (métadonnée technique, non
     * comparé ici), seul le succès répété est vérifié.
     */
    public function test_le_pdf_reste_regenerable_pour_une_facture_vtc(): void
    {
        $invoice = $this->makeVtcInvoice();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->get(route('invoices.pdf', $invoice))->assertSuccessful();
        $this->get(route('invoices.pdf', $invoice))->assertSuccessful();
    }
}
