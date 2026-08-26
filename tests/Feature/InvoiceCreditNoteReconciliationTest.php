<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier "réconciliation avoirs" (D1-D7, validés) — le reste dû et
 * le statut de paiement d'une Invoice doivent désormais tenir compte
 * des avoirs (CreditNote, T24) en plus des paiements (InvoicePayment,
 * T31), jamais l'un sans l'autre. Couvre :
 * - D1 : reste dû = total_ttc − avoirs − paiements ;
 * - D2 : 4e statut PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE, distinct de
 *   PAYMENT_STATUS_PAID (qui exige toujours un encaissement réel) ;
 * - D3 : amountRemaining() plafonné à 0, excédent exposé séparément
 *   via creditBalance() (jamais négatif, jamais perdu).
 *
 * TVA 0% (même convention que InvoicePaymentTest) : total_ttc =
 * total_ht, calcul de référence simple et non ambigu. Chaque ligne de
 * facture a un prix distinct pour permettre de créditer une ligne
 * précise sans ambiguïté sur le montant de l'avoir résultant.
 *
 * Ce fichier ne couvre PAS encore le plafond de InvoicePayment::recordFor()
 * (D5, étape suivante du chantier) : les paiements enregistrés ici
 * restent volontairement dans les limites déjà acceptées par le
 * plafond actuel (total_ttc brut) pour isoler strictement la
 * responsabilité de ce test aux méthodes de Invoice.
 */
class InvoiceCreditNoteReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        TaxRate::create([
            'label' => 'Vente 0%',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 0,
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

    private function makeProduct(float $price): Product
    {
        return Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Article Réconciliation',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => $price,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Client Réconciliation',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
    }

    /**
     * Facture expédiée avec une ligne par prix indiqué dans
     * $linePrices (quantité 1 chacune, TVA 0% — cf. setUp) : chaque
     * ligne a un montant exact et distinct, connu à l'avance, pour
     * pouvoir créditer une ligne précise dans les tests ci-dessous.
     */
    private function makeInvoiceWithLines(array $linePrices): Invoice
    {
        $customer = $this->makeCustomer();
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);

        $shipped = [];
        foreach ($linePrices as $price) {
            $item = SalesOrderItem::create([
                'sales_order_id' => $order->id,
                'product_id' => $this->makeProduct($price)->id,
                'quantity_ordered' => 1,
                'unit_price' => $price,
            ]);
            $shipped[$item->id] = 1;
        }

        $order->markAsConfirmed();
        $order->fresh()->ship($shipped);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    /**
     * Émet un avoir portant sur UNE SEULE ligne de la facture,
     * désignée par son indice de création (0-based) — permet des
     * scénarios où le montant de chaque avoir est connu d'avance.
     */
    private function creditLine(Invoice $invoice, int $lineIndex): CreditNote
    {
        $line = $invoice->lines()->get()[$lineIndex];

        return CreditNote::generateFromInvoice($invoice, [$line->id], 'Retour produit', CreditNote::SETTLEMENT_REFUND);
    }

    /*
     * =================================================================
     * Cas a — sans avoir, sans paiement (non-régression)
     * =================================================================
     */

    public function test_sans_avoir_et_sans_paiement_le_reste_du_egale_le_total_ttc(): void
    {
        $invoice = $this->makeInvoiceWithLines([1000]);

        $this->assertSame(0.0, $invoice->creditedAmount());
        $this->assertSame(0.0, $invoice->amountPaid());
        $this->assertSame(1000.0, $invoice->amountRemaining());
        $this->assertSame(0.0, $invoice->creditBalance());
        $this->assertSame(Invoice::PAYMENT_STATUS_UNPAID, $invoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas b/c — paiements seuls, sans avoir (non-régression stricte)
     * =================================================================
     */

    public function test_partiellement_payee_sans_avoir_comportement_inchange(): void
    {
        $invoice = $this->makeInvoiceWithLines([1000]);
        InvoicePayment::recordFor($invoice, 400, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->creditedAmount());
        $this->assertSame(600.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $freshInvoice->paymentStatus());
    }

    public function test_totalement_payee_sans_avoir_comportement_inchange(): void
    {
        $invoice = $this->makeInvoiceWithLines([1000]);
        InvoicePayment::recordFor($invoice, 1000, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas d — avoir partiel, sans paiement
     * =================================================================
     */

    public function test_avoir_partiel_sans_paiement_reduit_le_reste_du(): void
    {
        $invoice = $this->makeInvoiceWithLines([600, 400]);
        $this->creditLine($invoice, 0); // avoir de 600

        $freshInvoice = $invoice->fresh();
        $this->assertSame(600.0, $freshInvoice->creditedAmount());
        $this->assertSame(400.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        // D2 : rien n'a été payé, ce n'est PAS "soldée par avoir" (il
        // reste 400 € dus), donc toujours "non payée".
        $this->assertSame(Invoice::PAYMENT_STATUS_UNPAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas e — avoir total, sans paiement (D2 : nouveau statut)
     * =================================================================
     */

    public function test_avoir_total_sans_paiement_statut_soldee_par_avoir(): void
    {
        $invoice = $this->makeInvoiceWithLines([600, 400]);
        $this->creditLine($invoice, 0);
        $this->creditLine($invoice->fresh(), 1);

        $freshInvoice = $invoice->fresh();
        $this->assertSame(1000.0, $freshInvoice->creditedAmount());
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE, $freshInvoice->paymentStatus());
        // Jamais confondue avec un vrai encaissement (D2).
        $this->assertNotSame(Invoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas f — avoir + paiement combinés
     * =================================================================
     */

    public function test_avoir_partiel_puis_paiement_egal_au_net_devient_payee(): void
    {
        $invoice = $this->makeInvoiceWithLines([600, 400]);
        $this->creditLine($invoice, 0); // net = 400

        InvoicePayment::recordFor($invoice->fresh(), 400, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(400.0, $freshInvoice->amountPaid());
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    public function test_avoir_partiel_puis_paiement_partiel_du_net_reste_partielle(): void
    {
        $invoice = $this->makeInvoiceWithLines([600, 400]);
        $this->creditLine($invoice, 0); // net = 400

        InvoicePayment::recordFor($invoice->fresh(), 150, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(250.0, $freshInvoice->amountRemaining());
        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas g — plusieurs avoirs (cumul sans double comptage)
     * =================================================================
     */

    public function test_plusieurs_avoirs_partiels_se_cumulent_sans_double_comptage(): void
    {
        $invoice = $this->makeInvoiceWithLines([300, 300, 400]);
        $this->creditLine($invoice, 0);
        $this->creditLine($invoice->fresh(), 1);

        $freshInvoice = $invoice->fresh();
        $this->assertCount(2, $freshInvoice->creditNotes);
        $this->assertSame(600.0, $freshInvoice->creditedAmount());
        $this->assertSame(400.0, $freshInvoice->amountRemaining());
        $this->assertSame(Invoice::PAYMENT_STATUS_UNPAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Cas h — plusieurs paiements combinés à un avoir
     * =================================================================
     */

    public function test_plusieurs_paiements_avec_avoir_convergent_vers_le_solde_net(): void
    {
        $invoice = $this->makeInvoiceWithLines([600, 400]);
        $this->creditLine($invoice, 0); // net = 400

        InvoicePayment::recordFor($invoice->fresh(), 150, now()->toDateString());
        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());

        InvoicePayment::recordFor($invoice->fresh(), 150, now()->toDateString());
        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());

        InvoicePayment::recordFor($invoice->fresh(), 100, now()->toDateString());
        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
        $this->assertCount(3, $freshInvoice->payments);
    }

    /*
     * =================================================================
     * Cas i — un avoir ne peut structurellement jamais dépasser le
     * total_ttc (garanti par la contrainte UNIQUE sur
     * credit_note_lines.invoice_line_id, cf. CreditNoteLine)
     * =================================================================
     */

    public function test_les_avoirs_ne_peuvent_jamais_structurellement_depasser_le_total_ttc(): void
    {
        $invoice = $this->makeInvoiceWithLines([600, 400]);
        $this->creditLine($invoice, 0);
        $this->creditLine($invoice->fresh(), 1);

        $freshInvoice = $invoice->fresh();
        $this->assertSame(1000.0, $freshInvoice->creditedAmount());
        $this->assertLessThanOrEqual($freshInvoice->total_ttc, $freshInvoice->creditedAmount());

        // Toute tentative d'un 3e avoir échoue : plus aucune ligne
        // créditable (barrière déjà validée en T24, non modifiée ici).
        $this->expectException(\Exception::class);
        CreditNote::generateFromInvoice($freshInvoice, [$freshInvoice->lines()->first()->id], 'Nouvelle tentative', CreditNote::SETTLEMENT_REFUND);
    }

    /*
     * =================================================================
     * Cas i réel / j — avoir émis après un paiement qui dépasse le
     * nouveau montant net : solde créditeur (D3), jamais un négatif
     * =================================================================
     */

    public function test_avoir_emis_apres_un_paiement_qui_depasse_le_nouveau_net_laisse_un_solde_crediteur(): void
    {
        $invoice = $this->makeInvoiceWithLines([700, 300]);
        // Paiement de 800 € accepté par le plafond ACTUEL de
        // recordFor() (total_ttc brut = 1000, 800 <= 1000) — le
        // plafond net (D5) n'est pas encore implémenté à cette étape.
        InvoicePayment::recordFor($invoice, 800, now()->toDateString());

        $this->creditLine($invoice->fresh(), 1); // avoir de 300 -> net = 700

        $freshInvoice = $invoice->fresh();
        // Montant net dû après avoir (calculé ici via les seules
        // méthodes publiques, netTotalDue() restant privée par choix
        // D6 — aucune nouvelle API publique au-delà de ce qu'exigent
        // D1-D5) : total_ttc − creditedAmount() = 1000 − 300 = 700.
        $this->assertSame(700.0, round((float) $freshInvoice->total_ttc - $freshInvoice->creditedAmount(), 2));
        $this->assertSame(800.0, $freshInvoice->amountPaid());
        // D3 : jamais négatif, plafonné à 0.
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        // D3 : l'excédent (100 €) est exposé séparément, jamais perdu.
        $this->assertSame(100.0, $freshInvoice->creditBalance());
        // De l'argent réel a bien été encaissé (800 € > net 700 €) :
        // le statut reste "payée", pas "soldée par avoir".
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    public function test_facture_totalement_payee_puis_avoir_genere_un_solde_crediteur_egal_a_lavoir(): void
    {
        $invoice = $this->makeInvoiceWithLines([600, 400]);
        InvoicePayment::recordFor($invoice, 1000, now()->toDateString());

        $this->creditLine($invoice->fresh(), 1); // avoir de 400, émis après paiement intégral

        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(400.0, $freshInvoice->creditBalance());
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Précision monétaire — avoirs et paiements à centimes délicats
     * =================================================================
     */

    public function test_la_precision_des_montants_ne_derive_pas_avec_avoir_et_paiements_flottants(): void
    {
        $invoice = $this->makeInvoiceWithLines([66.67, 33.33]);
        $this->creditLine($invoice, 1); // avoir de 33.33 -> net = 66.67

        InvoicePayment::recordFor($invoice->fresh(), 33.33, now()->toDateString());
        InvoicePayment::recordFor($invoice->fresh(), 33.33, now()->toDateString());
        $payment = InvoicePayment::recordFor($invoice->fresh(), 0.01, now()->toDateString());

        $this->assertNotNull($payment->id);
        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(0.0, $freshInvoice->creditBalance());
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    /*
     * =================================================================
     * Sanity check du 4e statut
     * =================================================================
     */

    public function test_le_statut_soldee_par_avoir_a_bien_une_valeur_dediee_distincte(): void
    {
        $this->assertSame('soldee_par_avoir', Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE);
        $this->assertNotSame(Invoice::PAYMENT_STATUS_PAID, Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE);
        $this->assertNotSame(Invoice::PAYMENT_STATUS_UNPAID, Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE);
        $this->assertNotSame(Invoice::PAYMENT_STATUS_PARTIAL, Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE);
    }
}
