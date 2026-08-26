<?php

namespace Tests\Feature;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
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
 * Étape T31 — action "Enregistrer un paiement" sur ViewInvoice.
 * Symétrique exact de SupplierInvoicePaymentActionTest (T30). Vérifie
 * explicitement qu'elle porte sa propre garde ->authorize() (pas
 * seulement ->visible()) — un viewer ne doit jamais pouvoir enregistrer
 * un paiement, même par appel Livewire direct/forgé (gabarit T25/T26,
 * jamais le helper callAction() qui pré-vérifie lui-même la
 * visibilité).
 */
class InvoicePaymentActionTest extends TestCase
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

    /**
     * Facture générée par un manager déjà attribué à l'entrepôt par
     * défaut (T19, D2 fail-closed — nécessaire pour pouvoir expédier).
     */
    private function makeInvoiceAsManager(User $manager): Invoice
    {
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));

        $customer = Customer::create([
            'name' => 'Client Action Paiement',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Action Paiement',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 1,
            'unit_price' => 1000,
        ]);

        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 1]);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    public function test_laction_est_visible_pour_un_manager_sur_une_facture_non_payee(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeInvoiceAsManager($manager);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('recordPayment');
    }

    public function test_laction_est_masquee_une_fois_la_facture_integralement_payee(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeInvoiceAsManager($manager);
        InvoicePayment::recordFor($invoice, (float) $invoice->total_ttc, now()->toDateString());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('recordPayment');
    }

    public function test_laction_reste_invisible_pour_un_viewer(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeInvoiceAsManager($manager);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('recordPayment');
    }

    public function test_appeler_laction_enregistre_effectivement_un_paiement(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeInvoiceAsManager($manager);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('recordPayment', data: [
                'amount' => 400,
                'paid_at' => now()->toDateString(),
                'reference' => 'VIR-TEST',
                'notes' => 'Test action',
            ]);

        $this->assertSame(1, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());
    }

    /**
     * Étape T25/T26 — appel direct de mountAction() (pas le helper de
     * test callAction(), qui pré-vérifie lui-même assertActionVisible()
     * et ne testerait donc jamais le contournement réel) : reproduit un
     * appel Livewire forgé, indépendant de ce que l'interface affiche.
     * ->authorize() doit bloquer réellement l'exécution.
     */
    public function test_un_viewer_ne_peut_pas_enregistrer_un_paiement_par_appel_direct_de_laction(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeInvoiceAsManager($manager);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->call('mountAction', 'recordPayment');

        $this->assertSame(0, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(Invoice::PAYMENT_STATUS_UNPAID, $invoice->fresh()->paymentStatus());
    }

    /**
     * Contrôle de non-régression (règle 7) — un admin doit pouvoir
     * enregistrer un paiement exactement comme un manager.
     */
    public function test_un_admin_peut_toujours_enregistrer_un_paiement_via_laction(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeInvoiceAsManager($manager);

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('recordPayment', data: [
                'amount' => (float) $invoice->total_ttc,
                'paid_at' => now()->toDateString(),
            ]);

        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $invoice->fresh()->paymentStatus());
    }
}
