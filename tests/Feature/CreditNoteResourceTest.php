<?php

namespace Tests\Feature;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T24 — CreditNoteResource : strictement en lecture, HORS
 * scoping entrepôt T20/T21 (mêmes règles que InvoiceResource, T23).
 */
class CreditNoteResourceTest extends TestCase
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

    private function makeCreditNote(): CreditNote
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Resource',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
        $customer = Customer::create([
            'name' => 'Client CN Resource',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
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

        return CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif test', CreditNote::SETTLEMENT_REFUND);
    }

    public function test_admin_peut_consulter_la_liste_des_avoirs(): void
    {
        $creditNote = $this->makeCreditNote();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(ListCreditNotes::class)->assertCanSeeTableRecords([$creditNote]);
    }

    public function test_manager_peut_consulter_la_liste_des_avoirs(): void
    {
        $creditNote = $this->makeCreditNote();
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ListCreditNotes::class)->assertCanSeeTableRecords([$creditNote]);
    }

    public function test_viewer_peut_consulter_la_liste_des_avoirs(): void
    {
        $creditNote = $this->makeCreditNote();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ListCreditNotes::class)->assertCanSeeTableRecords([$creditNote]);
    }

    public function test_un_chauffeur_ne_peut_pas_consulter_les_avoirs(): void
    {
        $this->makeCreditNote();
        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur Avoir', 'user_id' => $user->id]);
        $this->actingAs($user);

        $this->get(CreditNoteResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Hors scoping entrepôt (conséquence directe de T23/D4) : un manager
     * sans aucun entrepôt attribué voit quand même les avoirs.
     */
    public function test_un_manager_sans_aucun_entrepot_attribue_voit_quand_meme_les_avoirs(): void
    {
        $creditNote = $this->makeCreditNote();
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);

        Livewire::test(ListCreditNotes::class)->assertCanSeeTableRecords([$creditNote]);
    }

    public function test_la_resource_ne_declare_aucune_page_de_creation_ou_edition(): void
    {
        $pages = CreditNoteResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }
}
