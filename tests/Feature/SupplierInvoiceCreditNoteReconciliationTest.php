<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier "avoir fournisseur" (réconciliation) — le reste dû et le
 * statut de paiement d'une SupplierInvoice doivent désormais tenir
 * compte des avoirs (SupplierCreditNote, T32) en plus des paiements
 * (SupplierInvoicePayment, T30), jamais l'un sans l'autre. Réplique
 * exactement InvoiceCreditNoteReconciliationTest (chantier
 * "réconciliation avoirs" côté vente), adapté à la granularité montant
 * global de SupplierCreditNote (pas de lignes, contrairement à
 * CreditNote/CreditNoteLine — décision validée de l'étude préalable).
 * Couvre :
 * - reste dû = total_ttc − avoirs − paiements ;
 * - 4e statut PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE, distinct de
 *   PAYMENT_STATUS_PAID (qui exige toujours un décaissement réel) ;
 * - amountRemaining() plafonné à 0, excédent exposé séparément via
 *   creditBalance() (jamais négatif, jamais perdu).
 */
class SupplierInvoiceCreditNoteReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplierInvoice(float $totalTtc): SupplierInvoice
    {
        $supplier = Supplier::create(['name' => 'Fournisseur Réconciliation']);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Réconciliation',
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

    private function creditAmount(SupplierInvoice $invoice, float $amount): SupplierCreditNote
    {
        return SupplierCreditNote::recordFor($invoice, 'AV-'.uniqid(), now()->toDateString(), $amount, 0, $amount);
    }

    /*
     * =================================================================
     * Cas a — sans avoir, sans paiement (non-régression)
     * =================================================================
     */

    public function test_sans_avoir_et_sans_paiement_le_reste_du_egale_le_total_ttc(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->assertSame(0.0, $invoice->creditedAmount());
        $this->assertSame(0.0, $invoice->amountPaid());
        $this->assertSame(1000.0, $invoice->amountRemaining());
        $this->assertSame(0.0, $invoice->creditBalance());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_UNPAID, $invoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas b/c — paiements seuls, sans avoir (non-régression stricte)
     * =================================================================
     */

    public function test_partiellement_payee_sans_avoir_comportement_inchange(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierInvoicePayment::recordFor($invoice, 400, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->creditedAmount());
        $this->assertSame(600.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, $freshInvoice->paymentStatus());
    }

    public function test_totalement_payee_sans_avoir_comportement_inchange(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierInvoicePayment::recordFor($invoice, 1000, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas d — avoir partiel, sans paiement
     * =================================================================
     */

    public function test_avoir_partiel_sans_paiement_reduit_le_reste_du(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $this->creditAmount($invoice, 600);

        $freshInvoice = $invoice->fresh();
        $this->assertSame(600.0, $freshInvoice->creditedAmount());
        $this->assertSame(400.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        // Rien n'a été payé, ce n'est PAS "soldée par avoir" (il reste
        // 400 € dus), donc toujours "non payée".
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_UNPAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas e — avoir total, sans paiement (4e statut)
     * =================================================================
     */

    public function test_avoir_total_sans_paiement_statut_soldee_par_avoir(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $this->creditAmount($invoice, 600);
        $this->creditAmount($invoice->fresh(), 400);

        $freshInvoice = $invoice->fresh();
        $this->assertSame(1000.0, $freshInvoice->creditedAmount());
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE, $freshInvoice->paymentStatus());
        // Jamais confondue avec un vrai décaissement.
        $this->assertNotSame(SupplierInvoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas f — avoir + paiement combinés
     * =================================================================
     */

    public function test_avoir_partiel_puis_paiement_egal_au_net_devient_payee(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $this->creditAmount($invoice, 600); // net = 400

        SupplierInvoicePayment::recordFor($invoice->fresh(), 400, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(400.0, $freshInvoice->amountPaid());
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    public function test_avoir_partiel_puis_paiement_partiel_du_net_reste_partielle(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $this->creditAmount($invoice, 600); // net = 400

        SupplierInvoicePayment::recordFor($invoice->fresh(), 150, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(250.0, $freshInvoice->amountRemaining());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas g — plusieurs avoirs (cumul sans double comptage)
     * =================================================================
     */

    public function test_plusieurs_avoirs_partiels_se_cumulent_sans_double_comptage(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $this->creditAmount($invoice, 300);
        $this->creditAmount($invoice->fresh(), 300);

        $freshInvoice = $invoice->fresh();
        $this->assertCount(2, $freshInvoice->creditNotes);
        $this->assertSame(600.0, $freshInvoice->creditedAmount());
        $this->assertSame(400.0, $freshInvoice->amountRemaining());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_UNPAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas h — plusieurs paiements combinés à un avoir
     * =================================================================
     */

    public function test_plusieurs_paiements_avec_avoir_convergent_vers_le_solde_net(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $this->creditAmount($invoice, 600); // net = 400

        SupplierInvoicePayment::recordFor($invoice->fresh(), 150, now()->toDateString());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());

        SupplierInvoicePayment::recordFor($invoice->fresh(), 150, now()->toDateString());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());

        SupplierInvoicePayment::recordFor($invoice->fresh(), 100, now()->toDateString());
        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
        $this->assertCount(3, $freshInvoice->payments);
    }

    /*
     * =================================================================
     * Cas i — un avoir ne peut structurellement jamais dépasser le
     * total_ttc (plafond de SupplierCreditNote::recordFor(), T32)
     * =================================================================
     */

    public function test_les_avoirs_ne_peuvent_jamais_structurellement_depasser_le_total_ttc(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $this->creditAmount($invoice, 600);
        $this->creditAmount($invoice->fresh(), 400);

        $freshInvoice = $invoice->fresh();
        $this->assertSame(1000.0, $freshInvoice->creditedAmount());
        $this->assertLessThanOrEqual($freshInvoice->total_ttc, $freshInvoice->creditedAmount());

        // Toute tentative d'un 3e avoir échoue : plus aucun solde
        // créditable (plafond déjà validé en T32, non modifié ici).
        $this->expectException(\Exception::class);
        $this->creditAmount($freshInvoice, 0.01);
    }

    /*
     * =================================================================
     * Cas j — avoir reçu après un paiement qui dépasse le nouveau
     * montant net : solde créditeur, jamais un négatif
     * =================================================================
     */

    public function test_avoir_recu_apres_un_paiement_qui_depasse_le_nouveau_net_laisse_un_solde_crediteur(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        // Paiement de 800 € accepté par le plafond ACTUEL de
        // recordFor() (total_ttc brut = 1000, 800 <= 1000).
        SupplierInvoicePayment::recordFor($invoice, 800, now()->toDateString());

        $this->creditAmount($invoice->fresh(), 300); // net = 700

        $freshInvoice = $invoice->fresh();
        $this->assertSame(700.0, round((float) $freshInvoice->total_ttc - $freshInvoice->creditedAmount(), 2));
        $this->assertSame(800.0, $freshInvoice->amountPaid());
        // Jamais négatif, plafonné à 0.
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        // L'excédent (100 €) est exposé séparément, jamais perdu.
        $this->assertSame(100.0, $freshInvoice->creditBalance());
        // De l'argent réel a bien été versé (800 € > net 700 €) : le
        // statut reste "payée", pas "soldée par avoir".
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    public function test_facture_totalement_payee_puis_avoir_genere_un_solde_crediteur_egal_a_lavoir(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierInvoicePayment::recordFor($invoice, 1000, now()->toDateString());

        $this->creditAmount($invoice->fresh(), 400); // avoir reçu après paiement intégral

        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(400.0, $freshInvoice->creditBalance());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Précision monétaire — avoir et paiements à centimes délicats
     * =================================================================
     */

    public function test_la_precision_des_montants_ne_derive_pas_avec_avoir_et_paiements_flottants(): void
    {
        $invoice = $this->makeSupplierInvoice(100);
        $this->creditAmount($invoice, 33.33); // net = 66.67

        SupplierInvoicePayment::recordFor($invoice->fresh(), 33.33, now()->toDateString());
        SupplierInvoicePayment::recordFor($invoice->fresh(), 33.33, now()->toDateString());
        $payment = SupplierInvoicePayment::recordFor($invoice->fresh(), 0.01, now()->toDateString());

        $this->assertNotNull($payment->id);
        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Sanity check du 4e statut
     * =================================================================
     */

    public function test_le_statut_soldee_par_avoir_a_bien_une_valeur_dediee_distincte(): void
    {
        $this->assertSame('soldee_par_avoir', SupplierInvoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE);
        $this->assertNotSame(SupplierInvoice::PAYMENT_STATUS_PAID, SupplierInvoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE);
        $this->assertNotSame(SupplierInvoice::PAYMENT_STATUS_UNPAID, SupplierInvoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE);
        $this->assertNotSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, SupplierInvoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE);
    }
}
