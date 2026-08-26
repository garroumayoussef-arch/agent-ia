<?php

namespace Tests\Feature;

use App\Filament\Resources\SupplierInvoices\Pages\CreateSupplierInvoice;
use App\Filament\Resources\SupplierInvoices\Pages\ListSupplierInvoices;
use App\Filament\Resources\SupplierInvoices\Pages\ViewSupplierInvoice;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\Driver;
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
 * Étape T28 — Resource SupplierInvoices. HasRoleBasedAuthorization
 * (admin/manager en écriture) + BlocksChauffeurReadAccess (comme
 * PurchaseOrderResource). Aucune page Edit n'existe (immuabilité,
 * décision 4) — vérifié explicitement ici, pas seulement supposé.
 */
class SupplierInvoiceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function makeOrderedPurchaseOrder(): PurchaseOrder
    {
        $supplier = Supplier::create(['name' => 'Fournisseur Resource']);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Resource',
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
            'quantity_ordered' => 5,
            'unit_price' => 10,
        ]);
        $order->markAsOrdered();

        return $order->fresh();
    }

    private function makeSupplierInvoice(PurchaseOrder $order, float $totalTtc, string $suffix = ''): SupplierInvoice
    {
        return SupplierInvoice::create([
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'FF-'.uniqid().$suffix,
            'invoice_date' => now()->toDateString(),
            'total_ht' => $totalTtc,
            'tax_amount' => 0,
            'total_ttc' => $totalTtc,
        ]);
    }

    /*
     * =================================================================
     * canCreate() — admin/manager uniquement (HasRoleBasedAuthorization)
     * =================================================================
     */

    public function test_un_admin_peut_creer_une_facture_fournisseur(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->assertTrue(SupplierInvoiceResource::canCreate());
    }

    public function test_un_manager_peut_creer_une_facture_fournisseur(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->assertTrue(SupplierInvoiceResource::canCreate());
    }

    public function test_un_viewer_ne_peut_pas_creer_une_facture_fournisseur(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->assertFalse(SupplierInvoiceResource::canCreate());
    }

    /*
     * =================================================================
     * Lecture — ouverte à tous sauf un compte chauffeur
     * =================================================================
     */

    public function test_un_viewer_peut_consulter_la_liste(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->assertTrue(SupplierInvoiceResource::canViewAny());
    }

    public function test_un_chauffeur_ne_peut_pas_consulter_la_liste(): void
    {
        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur Achats', 'user_id' => $user->id]);
        $this->actingAs($user);

        $this->assertFalse(SupplierInvoiceResource::canViewAny());
    }

    /*
     * =================================================================
     * Création via le formulaire Filament réel
     * =================================================================
     */

    public function test_creer_une_facture_fournisseur_via_le_formulaire_persiste_les_donnees(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $order = $this->makeOrderedPurchaseOrder();

        Livewire::test(CreateSupplierInvoice::class)
            ->fillForm([
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->id,
                'supplier_invoice_number' => 'FF-2026-001',
                'invoice_date' => now()->toDateString(),
                'total_ht' => 100,
                'tax_amount' => 20,
                'total_ttc' => 120,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('supplier_invoices', [
            'supplier_invoice_number' => 'FF-2026-001',
            'purchase_order_id' => $order->id,
        ]);
    }

    /*
     * =================================================================
     * Aucune page Edit (immuabilité, décision 4)
     * =================================================================
     */

    public function test_aucune_route_edit_nest_enregistree_pour_cette_resource(): void
    {
        $this->assertArrayNotHasKey('edit', SupplierInvoiceResource::getPages());
    }

    public function test_consulter_une_facture_fournisseur_deja_enregistree_fonctionne(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $order = $this->makeOrderedPurchaseOrder();
        $invoice = SupplierInvoice::create([
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'FF-2026-002',
            'invoice_date' => now()->toDateString(),
            'total_ht' => 100,
            'tax_amount' => 20,
            'total_ttc' => 120,
        ]);

        Livewire::test(ViewSupplierInvoice::class, ['record' => $invoice->getKey()])
            ->assertSuccessful();
    }

    /*
     * =================================================================
     * Chantier A — filtre par statut de paiement (colonne calculée),
     * symétrique exact du filtre sur InvoicesTable
     * =================================================================
     */

    public function test_le_filtre_statut_de_paiement_retourne_le_bon_sous_ensemble_de_factures(): void
    {
        $order = $this->makeOrderedPurchaseOrder();

        $invoiceUnpaid = $this->makeSupplierInvoice($order, 120, '-unpaid');

        $invoicePartial = $this->makeSupplierInvoice($order, 120, '-partial');
        SupplierInvoicePayment::recordFor($invoicePartial, 50, now()->toDateString());

        $invoicePaid = $this->makeSupplierInvoice($order, 120, '-paid');
        SupplierInvoicePayment::recordFor($invoicePaid, 120, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ListSupplierInvoices::class)
            ->filterTable('payment_status', SupplierInvoice::PAYMENT_STATUS_UNPAID)
            ->assertCanSeeTableRecords([$invoiceUnpaid])
            ->assertCanNotSeeTableRecords([$invoicePartial, $invoicePaid]);

        Livewire::test(ListSupplierInvoices::class)
            ->filterTable('payment_status', SupplierInvoice::PAYMENT_STATUS_PARTIAL)
            ->assertCanSeeTableRecords([$invoicePartial])
            ->assertCanNotSeeTableRecords([$invoiceUnpaid, $invoicePaid]);

        Livewire::test(ListSupplierInvoices::class)
            ->filterTable('payment_status', SupplierInvoice::PAYMENT_STATUS_PAID)
            ->assertCanSeeTableRecords([$invoicePaid])
            ->assertCanNotSeeTableRecords([$invoiceUnpaid, $invoicePartial]);
    }

    /**
     * Sans valeur sélectionnée, le filtre reste inactif : toutes les
     * factures fournisseurs restent visibles, quel que soit leur statut
     * de paiement.
     */
    public function test_le_filtre_statut_de_paiement_sans_valeur_ne_masque_aucune_facture(): void
    {
        $order = $this->makeOrderedPurchaseOrder();
        $invoiceUnpaid = $this->makeSupplierInvoice($order, 120, '-unpaid');
        $invoicePaid = $this->makeSupplierInvoice($order, 120, '-paid');
        SupplierInvoicePayment::recordFor($invoicePaid, 120, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ListSupplierInvoices::class)
            ->filterTable('payment_status', null)
            ->assertCanSeeTableRecords([$invoiceUnpaid, $invoicePaid]);
    }

    /**
     * Robustesse contre le bruit flottant de l'affinité NUMERIC de
     * SQLite sur les colonnes decimal(10,2), symétrique exact du test
     * équivalent sur InvoiceResourceTest.
     */
    public function test_le_filtre_paye_resiste_au_bruit_flottant_dune_somme_de_paiements(): void
    {
        $order = $this->makeOrderedPurchaseOrder();
        $invoice = $this->makeSupplierInvoice($order, 48, '-precision');
        SupplierInvoicePayment::recordFor($invoice, 16, now()->toDateString());
        SupplierInvoicePayment::recordFor($invoice, 16, now()->toDateString());
        SupplierInvoicePayment::recordFor($invoice, 16, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ListSupplierInvoices::class)
            ->filterTable('payment_status', SupplierInvoice::PAYMENT_STATUS_PAID)
            ->assertCanSeeTableRecords([$invoice]);

        Livewire::test(ListSupplierInvoices::class)
            ->filterTable('payment_status', SupplierInvoice::PAYMENT_STATUS_PARTIAL)
            ->assertCanNotSeeTableRecords([$invoice]);
    }
}
