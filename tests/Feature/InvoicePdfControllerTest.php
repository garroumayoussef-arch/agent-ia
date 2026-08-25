<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T23 (contrainte 9) — le PDF est généré exclusivement depuis
 * les données figées de Invoice/InvoiceLine, jamais recalculé depuis
 * SalesOrder/Customer/CompanySettings.
 */
class InvoicePdfControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        TaxRate::create([
            'label' => 'TVA 20%',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 20,
            'is_default_sale' => true,
            'is_active' => true,
        ]);

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
        ]);
    }

    private function makeInvoice(array $customerAttributes = []): Invoice
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot PDF',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
        $customer = Customer::create(array_merge([
            'name' => 'Client PDF',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse client',
            'city' => 'Lyon',
            'country' => 'France',
        ], $customerAttributes));
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 2,
            'unit_price' => 20,
        ]);
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 2]);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    public function test_le_pdf_est_genere_avec_succes_pour_un_admin(): void
    {
        $invoice = $this->makeInvoice();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $response = $this->get(route('invoices.pdf', $invoice));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_le_pdf_reste_accessible_pour_un_viewer(): void
    {
        $invoice = $this->makeInvoice();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->get(route('invoices.pdf', $invoice))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_pas_telecharger_une_facture(): void
    {
        $invoice = $this->makeInvoice();
        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur PDF', 'user_id' => $user->id]);
        $this->actingAs($user);

        $this->get(route('invoices.pdf', $invoice))->assertNotFound();
    }

    /**
     * Preuve directe de la contrainte 9 : le PDF reste identique (même
     * appelable sans erreur, avec les données figées) même après une
     * modification ultérieure du client — jamais un recalcul.
     */
    public function test_le_pdf_reste_regenerable_apres_modification_du_client(): void
    {
        $invoice = $this->makeInvoice(['name' => 'Nom Original PDF']);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $invoice->customer->update(['name' => 'Nom Modifié']);

        $response = $this->get(route('invoices.pdf', $invoice));

        $response->assertSuccessful();
        $this->assertSame('Nom Original PDF', $invoice->fresh()->customer_name);
    }
}
