<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T28 — enregistrement d'une facture fournisseur reçue.
 * Contrairement à Invoice (T23), aucune génération/snapshot : les
 * montants sont saisis directement, le numéro est celui du
 * fournisseur. Immuable dès l'enregistrement (décision 4), un seul
 * PurchaseOrder par facture (décision 1), aucun champ de paiement
 * (décision 2), aucune pièce jointe (décision 3).
 */
class SupplierInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplier(): Supplier
    {
        return Supplier::create(['name' => 'Fournisseur Test']);
    }

    private function makeOrderedPurchaseOrder(Supplier $supplier, string $status = PurchaseOrder::STATUS_ORDERED): PurchaseOrder
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Achat',
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

        if ($status !== PurchaseOrder::STATUS_DRAFT) {
            $order->markAsOrdered();
        }

        if ($status === PurchaseOrder::STATUS_CANCELLED) {
            $order->cancel();
        }

        return $order->fresh();
    }

    private function makeSupplierInvoiceData(Supplier $supplier, PurchaseOrder $order): array
    {
        return [
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'FF-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'total_ht' => 100,
            'tax_amount' => 20,
            'total_ttc' => 120,
        ];
    }

    /*
     * =================================================================
     * Création
     * =================================================================
     */

    public function test_une_facture_fournisseur_est_creee_avec_succes_sur_un_bon_commande(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrderedPurchaseOrder($supplier, PurchaseOrder::STATUS_ORDERED);

        $invoice = SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order));

        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice->id]);
        $this->assertSame($supplier->id, $invoice->supplier_id);
        $this->assertSame($order->id, $invoice->purchase_order_id);
        $this->assertSame(120.0, (float) $invoice->total_ttc);
    }

    public function test_une_facture_fournisseur_est_creee_avec_succes_sur_un_bon_partiellement_recu(): void
    {
        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $supplier = $this->makeSupplier();
        $order = $this->makeOrderedPurchaseOrder($supplier, PurchaseOrder::STATUS_PARTIALLY_RECEIVED);
        $order->receive([$order->items->first()->id => 2]);

        $invoice = SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order->fresh()));

        $this->assertDatabaseHas('supplier_invoices', ['id' => $invoice->id]);
    }

    public function test_lutilisateur_authentifie_est_renseigne_automatiquement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $supplier = $this->makeSupplier();
        $order = $this->makeOrderedPurchaseOrder($supplier);

        $invoice = SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order));

        $this->assertSame($user->id, $invoice->user_id);
    }

    /*
     * =================================================================
     * Rejet sur un bon de commande brouillon/annulé
     * =================================================================
     */

    public function test_lenregistrement_est_rejete_pour_un_bon_de_commande_en_brouillon(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrderedPurchaseOrder($supplier, PurchaseOrder::STATUS_DRAFT);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('brouillon');

        SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order));
    }

    public function test_lenregistrement_est_rejete_pour_un_bon_de_commande_annule(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrderedPurchaseOrder($supplier, PurchaseOrder::STATUS_CANCELLED);

        $this->expectException(\Exception::class);

        SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order));
    }

    /*
     * =================================================================
     * Immuabilité (décision 4)
     * =================================================================
     */

    public function test_une_facture_fournisseur_ne_peut_pas_etre_modifiee_apres_enregistrement(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrderedPurchaseOrder($supplier);
        $invoice = SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être modifiée');

        $invoice->update(['total_ttc' => 999]);
    }

    public function test_une_facture_fournisseur_ne_peut_pas_etre_supprimee_apres_enregistrement(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrderedPurchaseOrder($supplier);
        $invoice = SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être supprimée');

        $invoice->delete();
    }

    /*
     * =================================================================
     * Relations
     * =================================================================
     */

    public function test_les_relations_supplier_purchaseorder_et_user_fonctionnent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $supplier = $this->makeSupplier();
        $order = $this->makeOrderedPurchaseOrder($supplier);
        $invoice = SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order));

        $this->assertTrue($invoice->supplier->is($supplier));
        $this->assertTrue($invoice->purchaseOrder->is($order));
        $this->assertTrue($invoice->user->is($user));

        $this->assertTrue($order->fresh()->supplierInvoices->contains($invoice));
        $this->assertTrue($supplier->fresh()->supplierInvoices->contains($invoice));
    }

    /**
     * Décision 1 (validée) — un PurchaseOrder peut recevoir PLUSIEURS
     * factures fournisseur (réceptions partielles facturées
     * séparément) : aucune contrainte d'unicité sur purchase_order_id.
     */
    public function test_un_bon_de_commande_peut_recevoir_plusieurs_factures_fournisseur(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrderedPurchaseOrder($supplier);

        SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order));
        SupplierInvoice::create($this->makeSupplierInvoiceData($supplier, $order));

        $this->assertCount(2, $order->fresh()->supplierInvoices);
    }
}
