<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Étape T24 — génération d'avoir (CreditNote::generateFromInvoice()).
 * Couvre D1 (motif obligatoire), D2 (blocs vendeur/acheteur figés
 * depuis la facture), D3 (settlement_type informatif, aucune
 * automatisation), D4 (partiel = ligne entière uniquement), D5 (aucun
 * sur-crédit possible), ainsi que l'immuabilité et la numérotation.
 */
class CreditNoteGenerationTest extends TestCase
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

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot T24',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => 20,
        ], $attributes));
    }

    private function makeCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Jean Dupont',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => '2 avenue des Clients',
            'postal_code' => '69000',
            'city' => 'Lyon',
            'country' => 'France',
        ], $attributes));
    }

    /**
     * Facture intégralement expédiée puis émise, avec $lineCount lignes
     * distinctes (produits différents), quantité 3 chacune.
     */
    private function makeShippedInvoice(int $lineCount = 2, ?Customer $customer = null): Invoice
    {
        $customer ??= $this->makeCustomer();
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);

        $shipped = [];
        for ($i = 0; $i < $lineCount; $i++) {
            $item = SalesOrderItem::create([
                'sales_order_id' => $order->id,
                'product_id' => $this->makeProduct()->id,
                'quantity_ordered' => 3,
                'unit_price' => 20,
            ]);
            $shipped[$item->id] = 3;
        }

        $order->markAsConfirmed();
        $order->fresh()->ship($shipped);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    /*
     * =================================================================
     * Avoir total
     * =================================================================
     */

    public function test_avoir_total_credite_toutes_les_lignes_et_bloque_toute_facturation_ulterieure(): void
    {
        $invoice = $this->makeShippedInvoice(2);
        $lineIds = $invoice->lines->pluck('id')->all();

        $creditNote = CreditNote::generateFromInvoice($invoice, $lineIds, 'Erreur de facturation', CreditNote::SETTLEMENT_REFUND);

        $this->assertSame(CreditNote::SCOPE_TOTAL, $creditNote->scope);
        $this->assertCount(2, $creditNote->lines);
        $this->assertTrue(CreditNote::creditableLinesFor($invoice)->isEmpty());

        $this->expectException(\Exception::class);
        CreditNote::generateFromInvoice($invoice, $lineIds, 'Nouvelle tentative', CreditNote::SETTLEMENT_REFUND);
    }

    /*
     * =================================================================
     * Avoir partiel
     * =================================================================
     */

    public function test_avoir_partiel_credite_un_sous_ensemble_et_laisse_le_reste_creditable(): void
    {
        $invoice = $this->makeShippedInvoice(2);
        $firstLineId = $invoice->lines->first()->id;

        $creditNote = CreditNote::generateFromInvoice($invoice, [$firstLineId], 'Produit défectueux', CreditNote::SETTLEMENT_REFUND);

        $this->assertSame(CreditNote::SCOPE_PARTIAL, $creditNote->scope);
        $this->assertCount(1, $creditNote->lines);
        $this->assertCount(1, CreditNote::creditableLinesFor($invoice->fresh()));
    }

    public function test_deux_avoirs_partiels_successifs_couvrent_progressivement_toute_la_facture(): void
    {
        $invoice = $this->makeShippedInvoice(2);
        [$lineA, $lineB] = $invoice->lines->all();

        CreditNote::generateFromInvoice($invoice, [$lineA->id], 'Motif A', CreditNote::SETTLEMENT_REFUND);
        $this->assertCount(1, CreditNote::creditableLinesFor($invoice->fresh()));

        CreditNote::generateFromInvoice($invoice, [$lineB->id], 'Motif B', CreditNote::SETTLEMENT_FUTURE_INVOICE);
        $this->assertCount(0, CreditNote::creditableLinesFor($invoice->fresh()));

        $this->assertSame(2, CreditNote::count());
    }

    /*
     * =================================================================
     * Sur-crédit — barrière applicative ET barrière base de données
     * =================================================================
     */

    public function test_impossible_de_crediter_deux_fois_la_meme_ligne_barriere_applicative(): void
    {
        $invoice = $this->makeShippedInvoice(2);
        $lineId = $invoice->lines->first()->id;

        CreditNote::generateFromInvoice($invoice, [$lineId], 'Premier avoir', CreditNote::SETTLEMENT_REFUND);

        $this->expectException(\Exception::class);

        try {
            CreditNote::generateFromInvoice($invoice, [$lineId], 'Tentative de doublon', CreditNote::SETTLEMENT_REFUND);
        } finally {
            $this->assertSame(1, CreditNoteLine::where('invoice_line_id', $lineId)->count());
        }
    }

    /**
     * Preuve directe de la barrière de dernier recours (migration) :
     * même en contournant totalement CreditNote::generateFromInvoice()
     * (insertion directe), la base refuse une deuxième CreditNoteLine
     * pour la même InvoiceLine.
     */
    public function test_la_contrainte_unique_en_base_empeche_le_sur_credit_meme_hors_generateFromInvoice(): void
    {
        $invoice = $this->makeShippedInvoice(1);
        $line = $invoice->lines->first();

        $firstCreditNote = CreditNote::generateFromInvoice($invoice, [$line->id], 'Premier avoir', CreditNote::SETTLEMENT_REFUND);

        // Un second CreditNote "conteneur" créé pour simuler un
        // contournement direct (jamais via generateFromInvoice) : copie
        // de toutes les données du premier avoir, seul le numéro change.
        $rogueData = $firstCreditNote->toArray();
        unset($rogueData['id'], $rogueData['created_at'], $rogueData['updated_at'], $rogueData['lines']);
        $rogueData['number'] = 'AV-2026-999999';

        $rogueCreditNote = CreditNote::create($rogueData);

        $this->expectException(QueryException::class);
        CreditNoteLine::create([
            'credit_note_id' => $rogueCreditNote->id,
            'invoice_line_id' => $line->id, // déjà créditée par $firstCreditNote
            'product_name' => $line->product_name,
            'quantity' => $line->quantity,
            'unit_price_ht' => $line->unit_price_ht,
            'subtotal_ht' => $line->subtotal_ht,
        ]);
    }

    /*
     * =================================================================
     * Facture déjà intégralement créditée
     * =================================================================
     */

    public function test_facture_deja_integralement_creditee_refuse_tout_nouvel_avoir(): void
    {
        $invoice = $this->makeShippedInvoice(1);
        $lineId = $invoice->lines->first()->id;

        CreditNote::generateFromInvoice($invoice, [$lineId], 'Avoir total', CreditNote::SETTLEMENT_REFUND);

        $this->expectExceptionMessage('déjà intégralement créditée');
        CreditNote::generateFromInvoice($invoice, [$lineId], 'Nouvelle tentative', CreditNote::SETTLEMENT_REFUND);
    }

    /*
     * =================================================================
     * D1 — motif obligatoire
     * =================================================================
     */

    public function test_le_motif_est_obligatoire(): void
    {
        $invoice = $this->makeShippedInvoice(1);
        $lineId = $invoice->lines->first()->id;

        $this->expectException(\Exception::class);
        CreditNote::generateFromInvoice($invoice, [$lineId], '', CreditNote::SETTLEMENT_REFUND);
    }

    /*
     * =================================================================
     * D3 — settlement_type obligatoire, valeurs limitées, informatif
     * =================================================================
     */

    public function test_le_mode_de_reglement_est_obligatoire_et_limite_aux_valeurs_autorisees(): void
    {
        $invoice = $this->makeShippedInvoice(1);
        $lineId = $invoice->lines->first()->id;

        $this->expectException(\Exception::class);
        CreditNote::generateFromInvoice($invoice, [$lineId], 'Motif valide', 'valeur_invalide');
    }

    public function test_les_deux_valeurs_de_reglement_sont_acceptees_sans_aucune_automatisation(): void
    {
        $invoiceA = $this->makeShippedInvoice(1);
        $creditNoteA = CreditNote::generateFromInvoice($invoiceA, [$invoiceA->lines->first()->id], 'Motif', CreditNote::SETTLEMENT_REFUND);
        $this->assertSame('refund', $creditNoteA->settlement_type);

        $invoiceB = $this->makeShippedInvoice(1);
        $creditNoteB = CreditNote::generateFromInvoice($invoiceB, [$invoiceB->lines->first()->id], 'Motif', CreditNote::SETTLEMENT_FUTURE_INVOICE);
        $this->assertSame('future_invoice', $creditNoteB->settlement_type);

        // Aucune trace d'un quelconque solde/ledger : aucune table de ce
        // type n'existe dans ce projet (T24 périmètre V1 strict).
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('customer_credit_balances'));
    }

    /*
     * =================================================================
     * D2 — blocs vendeur/acheteur figés depuis la FACTURE
     * =================================================================
     */

    public function test_les_blocs_vendeur_et_acheteur_sont_copies_depuis_la_facture_pas_les_donnees_actuelles(): void
    {
        $customer = $this->makeCustomer(['name' => 'Nom Facture']);
        $invoice = $this->makeShippedInvoice(1, $customer);

        // Modifications APRÈS émission de la facture, AVANT émission de
        // l'avoir : ni CompanySettings ni Customer ne doivent influencer
        // l'avoir — seule la facture déjà figée doit être utilisée (D2).
        $customer->update(['name' => 'Nom Modifié Après Facture']);
        CompanySettings::current()->update(['legal_name' => 'Magarrou Renommée']);

        $creditNote = CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif', CreditNote::SETTLEMENT_REFUND);

        $this->assertSame('Nom Facture', $creditNote->customer_name);
        $this->assertSame('Magarrou', $creditNote->seller_legal_name);
        $this->assertSame($invoice->customer_name, $creditNote->customer_name);
        $this->assertSame($invoice->seller_siren, $creditNote->seller_siren);
    }

    /*
     * =================================================================
     * Date d'émission — toujours aujourd'hui
     * =================================================================
     */

    public function test_la_date_demission_de_lavoir_est_toujours_la_date_du_jour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10'));
        $invoice = $this->makeShippedInvoice(1);

        Carbon::setTestNow(Carbon::parse('2026-03-05'));
        $creditNote = CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif', CreditNote::SETTLEMENT_REFUND);

        Carbon::setTestNow();

        $this->assertSame('2026-03-05', $creditNote->issued_at->toDateString());
        $this->assertNotSame($invoice->issued_at->toDateString(), $creditNote->issued_at->toDateString());
    }

    /*
     * =================================================================
     * Calcul HT/TVA/TTC — somme des lignes créditées uniquement
     * =================================================================
     */

    public function test_les_totaux_de_lavoir_partiel_correspondent_uniquement_aux_lignes_creditees(): void
    {
        $invoice = $this->makeShippedInvoice(2);
        $creditedLine = $invoice->lines->first();

        $creditNote = CreditNote::generateFromInvoice($invoice, [$creditedLine->id], 'Motif', CreditNote::SETTLEMENT_REFUND);

        $this->assertEqualsWithDelta((float) $creditedLine->subtotal_ht, (float) $creditNote->total_ht, 0.001);
        $this->assertEqualsWithDelta((float) $creditedLine->tax_amount, (float) $creditNote->tax_amount, 0.001);
        $this->assertEqualsWithDelta((float) $creditedLine->total_ttc, (float) $creditNote->total_ttc, 0.001);
        // Jamais les totaux de la facture entière (2 lignes) :
        $this->assertNotEqualsWithDelta((float) $invoice->total_ht, (float) $creditNote->total_ht, 0.001);
    }

    /*
     * =================================================================
     * Immuabilité
     * =================================================================
     */

    public function test_un_avoir_emis_ne_peut_pas_etre_modifie(): void
    {
        $invoice = $this->makeShippedInvoice(1);
        $creditNote = CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif', CreditNote::SETTLEMENT_REFUND);

        $this->expectException(\Exception::class);
        $creditNote->update(['total_ttc' => 999]);
    }

    public function test_un_avoir_emis_ne_peut_pas_etre_supprime(): void
    {
        $invoice = $this->makeShippedInvoice(1);
        $creditNote = CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif', CreditNote::SETTLEMENT_REFUND);

        $this->expectException(\Exception::class);
        $creditNote->delete();
    }

    public function test_une_ligne_davoir_ne_peut_pas_etre_modifiee(): void
    {
        $invoice = $this->makeShippedInvoice(1);
        $creditNote = CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif', CreditNote::SETTLEMENT_REFUND);
        $line = $creditNote->lines->first();

        $this->expectException(\Exception::class);
        $line->update(['quantity' => 999]);
    }

    public function test_une_ligne_davoir_ne_peut_pas_etre_supprimee(): void
    {
        $invoice = $this->makeShippedInvoice(1);
        $creditNote = CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif', CreditNote::SETTLEMENT_REFUND);
        $line = $creditNote->lines->first();

        $this->expectException(\Exception::class);

        try {
            $line->delete();
        } finally {
            $this->assertSame(1, $creditNote->lines()->count());
        }
    }

    /**
     * La facture d'origine, elle, reste évidemment intacte : la
     * génération d'un avoir n'écrit jamais sur Invoice/InvoiceLine.
     */
    public function test_la_facture_dorigine_reste_totalement_intacte_apres_emission_dun_avoir(): void
    {
        $invoice = $this->makeShippedInvoice(2);
        $originalTotalTtc = $invoice->total_ttc;

        CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Motif', CreditNote::SETTLEMENT_REFUND);

        $invoice->refresh();
        $this->assertEqualsWithDelta((float) $originalTotalTtc, (float) $invoice->total_ttc, 0.001);
    }
}
