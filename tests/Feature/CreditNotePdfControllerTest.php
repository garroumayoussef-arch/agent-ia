<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\CreditNote;
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
 * Étape T24 — le PDF de l'avoir est généré exclusivement depuis les
 * données figées de CreditNote/CreditNoteLine, jamais recalculé depuis
 * Invoice/Customer/CompanySettings.
 */
class CreditNotePdfControllerTest extends TestCase
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

    private function makeCreditNote(array $customerAttributes = []): CreditNote
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot PDF Avoir',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
        $customer = Customer::create(array_merge([
            'name' => 'Client PDF Avoir',
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

        $invoice = Invoice::generateFromSalesOrder($order->fresh());

        return CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif PDF', CreditNote::SETTLEMENT_REFUND);
    }

    public function test_le_pdf_est_genere_avec_succes_pour_un_admin(): void
    {
        $creditNote = $this->makeCreditNote();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $response = $this->get(route('credit-notes.pdf', $creditNote));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_le_pdf_reste_accessible_pour_un_viewer(): void
    {
        $creditNote = $this->makeCreditNote();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->get(route('credit-notes.pdf', $creditNote))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_pas_telecharger_un_avoir(): void
    {
        $creditNote = $this->makeCreditNote();
        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur PDF Avoir', 'user_id' => $user->id]);
        $this->actingAs($user);

        $this->get(route('credit-notes.pdf', $creditNote))->assertNotFound();
    }

    public function test_le_pdf_reste_regenerable_apres_modification_du_client(): void
    {
        $creditNote = $this->makeCreditNote(['name' => 'Nom Original Avoir']);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $creditNote->customer->update(['name' => 'Nom Modifié']);

        $response = $this->get(route('credit-notes.pdf', $creditNote));

        $response->assertSuccessful();
        $this->assertSame('Nom Original Avoir', $creditNote->fresh()->customer_name);
    }
}
