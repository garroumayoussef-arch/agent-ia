<?php

namespace Tests\Feature;

use App\Filament\Resources\SupplierInvoices\Pages\ViewSupplierInvoice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T30 — action "Enregistrer un paiement" sur ViewSupplierInvoice.
 * Vérifie explicitement qu'elle porte sa propre garde ->authorize()
 * (pas seulement ->visible()) — un viewer ne doit jamais pouvoir
 * enregistrer un paiement, même par appel Livewire direct/forgé
 * (gabarit T25/T26, jamais le helper callAction() qui pré-vérifie
 * lui-même la visibilité).
 */
class SupplierInvoicePaymentActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeSupplierInvoice(float $totalTtc): SupplierInvoice
    {
        $supplier = Supplier::create(['name' => 'Fournisseur Action Paiement']);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Action Paiement',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 0,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ]);
        $order = PurchaseOrder::create(['reference' => 'BC-'.uniqid(), 'supplier_id' => $supplier->id]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 1,
            'unit_price' => 10,
        ]);
        $order->markAsOrdered();

        return SupplierInvoice::create([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'FF-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'total_ht' => $totalTtc,
            'tax_amount' => 0,
            'total_ttc' => $totalTtc,
        ]);
    }

    public function test_laction_est_visible_pour_un_manager_sur_une_facture_non_payee(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $invoice = $this->makeSupplierInvoice(1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('recordPayment');
    }

    public function test_laction_est_masquee_une_fois_la_facture_integralement_payee(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierInvoicePayment::recordFor($invoice, 1000, now()->toDateString());

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('recordPayment');
    }

    public function test_laction_reste_invisible_pour_un_viewer(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));
        $invoice = $this->makeSupplierInvoice(1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('recordPayment');
    }

    public function test_appeler_laction_enregistre_effectivement_un_paiement(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $invoice = $this->makeSupplierInvoice(1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('recordPayment', data: [
                'amount' => 400,
                'paid_at' => now()->toDateString(),
                'reference' => 'VIR-TEST',
                'notes' => 'Test action',
            ]);

        $this->assertSame(1, SupplierInvoicePayment::where('supplier_invoice_id', $invoice->id)->count());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());
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
        $invoice = $this->makeSupplierInvoice(1000);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->call('mountAction', 'recordPayment');

        $this->assertSame(0, SupplierInvoicePayment::where('supplier_invoice_id', $invoice->id)->count());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_UNPAID, $invoice->fresh()->paymentStatus());
    }

    /**
     * Contrôle de non-régression (règle 7) — un admin doit pouvoir
     * enregistrer un paiement exactement comme un manager.
     */
    public function test_un_admin_peut_toujours_enregistrer_un_paiement_via_laction(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $invoice = $this->makeSupplierInvoice(1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('recordPayment', data: [
                'amount' => 1000,
                'paid_at' => now()->toDateString(),
            ]);

        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $invoice->fresh()->paymentStatus());
    }
}
