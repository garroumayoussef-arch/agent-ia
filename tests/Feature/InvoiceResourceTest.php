<?php

namespace Tests\Feature;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T23 — InvoiceResource : strictement en lecture, D4 (hors
 * scoping entrepôt T20/T21 — mêmes règles que SalesOrderResource,
 * jamais restreint par warehouse_user même pour un manager sans aucun
 * entrepôt attribué).
 */
class InvoiceResourceTest extends TestCase
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

        $settings = CompanySettings::current();
        $settings->update([
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

    private function makeInvoice(): Invoice
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot T23',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
        $customer = Customer::create([
            'name' => 'Client Resource Test',
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

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    public function test_admin_peut_consulter_la_liste_des_factures(): void
    {
        $invoice = $this->makeInvoice();
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$invoice]);
    }

    public function test_manager_peut_consulter_la_liste_des_factures(): void
    {
        $invoice = $this->makeInvoice();
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$invoice]);
    }

    /**
     * D4 — la facturation suit les autorisations normales de
     * SalesOrderResource (lecture ouverte à tous sauf chauffeur),
     * jamais le scoping entrepôt T20/T21.
     */
    public function test_viewer_peut_consulter_la_liste_des_factures(): void
    {
        $invoice = $this->makeInvoice();
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$invoice]);
    }

    public function test_un_chauffeur_ne_peut_pas_consulter_les_factures(): void
    {
        $this->makeInvoice();
        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur Facture', 'user_id' => $user->id]);
        $this->actingAs($user);

        $this->get(InvoiceResource::getUrl('index'))->assertForbidden();
    }

    /**
     * D4 (explicite) — un manager restreint T19/T20 à AUCUN entrepôt
     * voit quand même les factures : la facturation n'est jamais
     * scopée par entrepôt.
     */
    public function test_un_manager_sans_aucun_entrepot_attribue_voit_quand_meme_les_factures(): void
    {
        $invoice = $this->makeInvoice();
        $manager = User::factory()->create()->assignRole('manager');
        // Aucune attribution d'entrepôt (warehouse_user vide pour ce manager).
        $this->actingAs($manager);

        Livewire::test(ListInvoices::class)->assertCanSeeTableRecords([$invoice]);
    }

    public function test_la_resource_ne_declare_aucune_page_de_creation_ou_edition(): void
    {
        $pages = InvoiceResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }
}
