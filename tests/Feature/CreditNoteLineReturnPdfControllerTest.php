<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\CreditNoteLineReturn;
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
 * Chantier "bon de retour" (décisions 1 à 6, validées) — le PDF d'un
 * retour physique client est généré exclusivement depuis les données
 * déjà figées de CreditNoteLineReturn/CreditNoteLine, jamais
 * recalculé depuis Product/Customer/CompanySettings au moment du
 * téléchargement — même principe que CreditNotePdfControllerTest
 * (T24), appliqué au retour plutôt qu'à l'avoir lui-même.
 *
 * Décision 2 (validée) : contrairement à l'avoir, les informations
 * entreprise sont lues EN DIRECT depuis CompanySettings::current() —
 * un changement ultérieur de ces informations se répercute donc sur
 * le PDF, contrairement au nom du client (figé sur CreditNote).
 */
class CreditNoteLineReturnPdfControllerTest extends TestCase
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

    private function makeReturn(array $customerAttributes = []): CreditNoteLineReturn
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot PDF Retour',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
        $customer = Customer::create(array_merge([
            'name' => 'Client PDF Retour',
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
        $creditNote = CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif PDF Retour', CreditNote::SETTLEMENT_REFUND);
        $line = $creditNote->lines()->firstOrFail();

        return CreditNoteLineReturn::recordFor($line, 1, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());
    }

    public function test_le_pdf_est_genere_avec_succes_pour_un_admin(): void
    {
        $return = $this->makeReturn();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $response = $this->get(route('credit-note-line-returns.pdf', $return));

        $response->assertSuccessful();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_le_pdf_reste_accessible_pour_un_viewer(): void
    {
        $return = $this->makeReturn();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->get(route('credit-note-line-returns.pdf', $return))->assertSuccessful();
    }

    public function test_un_chauffeur_ne_peut_pas_telecharger_un_bon_de_retour(): void
    {
        $return = $this->makeReturn();
        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur PDF Retour', 'user_id' => $user->id]);
        $this->actingAs($user);

        $this->get(route('credit-note-line-returns.pdf', $return))->assertNotFound();
    }

    /**
     * Décision 2 (validée) — le PDF du retour, à la différence de celui
     * de l'avoir, redécouvre les informations entreprise à chaque appel
     * (aucun snapshot dédié introduit sur CreditNoteLineReturn) :
     * régénérable même après une modification ultérieure de
     * CompanySettings.
     */
    public function test_le_pdf_reste_regenerable_apres_modification_des_parametres_entreprise(): void
    {
        $return = $this->makeReturn();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        CompanySettings::current()->update(['legal_name' => 'Nouvelle Raison Sociale']);

        $response = $this->get(route('credit-note-line-returns.pdf', $return));

        $response->assertSuccessful();
    }

    /**
     * Non-régression : le nom du client, lui, reste figé sur CreditNote
     * (T24) — inchangé par ce chantier.
     */
    public function test_le_nom_du_client_fige_sur_lavoir_reste_inchange_apres_modification_du_client(): void
    {
        $return = $this->makeReturn(['name' => 'Nom Original Client']);
        $creditNote = $return->creditNoteLine->creditNote;
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $creditNote->customer->update(['name' => 'Nom Modifié']);

        $response = $this->get(route('credit-note-line-returns.pdf', $return));

        $response->assertSuccessful();
        $this->assertSame('Nom Original Client', $creditNote->fresh()->customer_name);
    }
}
