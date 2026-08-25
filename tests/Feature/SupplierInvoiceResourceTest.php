<?php

namespace Tests\Feature;

use App\Filament\Resources\SupplierInvoices\Pages\CreateSupplierInvoice;
use App\Filament\Resources\SupplierInvoices\Pages\ViewSupplierInvoice;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\Driver;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
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
}
