<?php

namespace Tests\Feature;

use App\Filament\Resources\SupplierInvoices\Pages\ViewSupplierInvoice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chantier "avoir fournisseur" — action "Enregistrer un avoir" sur
 * ViewSupplierInvoice. Vérifie explicitement qu'elle porte sa propre
 * garde ->authorize() (pas seulement ->visible()) — un viewer ne doit
 * jamais pouvoir enregistrer un avoir, même par appel Livewire
 * direct/forgé (même gabarit que SupplierInvoicePaymentActionTest,
 * T30).
 */
class SupplierCreditNoteActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeSupplierInvoice(float $totalTtc): SupplierInvoice
    {
        $supplier = Supplier::create(['name' => 'Fournisseur Action Avoir']);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Action Avoir',
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

    public function test_laction_est_visible_pour_un_manager_sur_une_facture_non_creditee(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $invoice = $this->makeSupplierInvoice(1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('recordSupplierCreditNote');
    }

    public function test_laction_est_masquee_une_fois_la_facture_integralement_creditee(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierCreditNote::recordFor($invoice, 'AV-001', now()->toDateString(), 1000, 0, 1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('recordSupplierCreditNote');
    }

    public function test_laction_reste_invisible_pour_un_viewer(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));
        $invoice = $this->makeSupplierInvoice(1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('recordSupplierCreditNote');
    }

    public function test_appeler_laction_enregistre_effectivement_un_avoir(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $invoice = $this->makeSupplierInvoice(1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('recordSupplierCreditNote', data: [
                'supplier_credit_note_number' => 'AV-TEST',
                'credit_note_date' => now()->toDateString(),
                'total_ht' => 400,
                'tax_amount' => 0,
                'total_ttc' => 400,
                'reason' => 'Marchandise défectueuse',
                'notes' => 'Test action',
            ]);

        $this->assertSame(1, SupplierCreditNote::where('supplier_invoice_id', $invoice->id)->count());
        $this->assertSame(400.0, SupplierCreditNote::totalCreditedFor($invoice->fresh()));
    }

    /**
     * Appel direct de mountAction() (pas le helper de test
     * callAction(), qui pré-vérifie lui-même assertActionVisible() et
     * ne testerait donc jamais le contournement réel) : reproduit un
     * appel Livewire forgé, indépendant de ce que l'interface affiche.
     * ->authorize() doit bloquer réellement l'exécution.
     */
    public function test_un_viewer_ne_peut_pas_enregistrer_un_avoir_par_appel_direct_de_laction(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->call('mountAction', 'recordSupplierCreditNote');

        $this->assertSame(0, SupplierCreditNote::where('supplier_invoice_id', $invoice->id)->count());
    }

    /**
     * Contrôle de non-régression — un admin doit pouvoir enregistrer
     * un avoir exactement comme un manager.
     */
    public function test_un_admin_peut_toujours_enregistrer_un_avoir_via_laction(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));
        $invoice = $this->makeSupplierInvoice(1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('recordSupplierCreditNote', data: [
                'supplier_credit_note_number' => 'AV-ADMIN',
                'credit_note_date' => now()->toDateString(),
                'total_ht' => 1000,
                'tax_amount' => 0,
                'total_ttc' => 1000,
            ]);

        $this->assertSame(1000.0, SupplierCreditNote::totalCreditedFor($invoice->fresh()));
    }

    /**
     * Non-régression — l'action "Enregistrer un paiement" (T30) doit
     * rester intacte, inchangée par l'ajout de la nouvelle action sur
     * la même page.
     */
    public function test_laction_paiement_reste_intacte_a_cote_de_la_nouvelle_action(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));
        $invoice = $this->makeSupplierInvoice(1000);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('recordPayment')
            ->assertActionVisible('recordSupplierCreditNote');
    }
}
