<?php

namespace Tests\Feature;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Widgets\CommercialOverview;
use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VtcRide;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T27 — widget de reporting commercial/financier (CommercialOverview).
 * 4 indicateurs (CA TTC facturé, nombre de factures, montant des
 * avoirs, CA net) × 4 périodes fixes, gabarit direct de
 * VtcRideOverviewTest. Périmètre strictement en lecture, aucune
 * modification d'Invoice/CreditNote/leurs lignes ici — seules des
 * factures/avoirs déjà existants sont créés via leur point d'entrée
 * public habituel (generateFromSalesOrder()/generateFromInvoice()).
 */
class CommercialOverviewTest extends TestCase
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
     * Crée, expédie et facture une commande d'un montant HT donné (TVA
     * 20% déjà configurée ci-dessus). Retourne la facture émise.
     */
    private function makeInvoice(float $unitPriceHt, int $quantity = 1, string $suffix = ''): Invoice
    {
        $customer = Customer::create([
            'name' => 'Client Reporting'.$suffix,
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Reporting'.$suffix,
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => $unitPriceHt,
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => $quantity,
            'unit_price' => $unitPriceHt,
        ]);

        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => $quantity]);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    /**
     * Chantier "facturation légale VTC" (D10, validé) — génère une
     * facture VTC (TVA 10%, distincte de la TVA 20% vente configurée en
     * setUp) pour prouver qu'elle n'est JAMAIS comptée par ce widget.
     */
    private function makeVtcInvoice(float $priceHt): Invoice
    {
        $rate = TaxRate::firstOrCreate(
            ['label' => 'VTC 10%'],
            ['type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true],
        );
        FiscalSetting::updateOrCreate(['activity' => FiscalSetting::ACTIVITY_VTC], ['tax_rate_id' => $rate->id]);

        $ride = VtcRide::create([
            'reference' => 'VTC-'.uniqid(),
            'customer_id' => Customer::create([
                'name' => 'Client VTC Reporting',
                'customer_type' => Customer::TYPE_INDIVIDUAL,
                'address' => 'Adresse',
                'city' => 'Lyon',
                'country' => 'France',
            ])->id,
            'driver_id' => Driver::create(['name' => 'Chauffeur Reporting VTC', 'is_active' => true])->id,
            'vehicle_id' => Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ', 'is_active' => true])->id,
            'price_ht' => $priceHt,
        ]);
        $ride->markAsConfirmed();

        return Invoice::generateFromVtcRide($ride->fresh());
    }

    /**
     * Force issued_at à une date arbitraire sans passer par Eloquent —
     * Invoice/CreditNote refusent toute mise à jour (immutabilité T23/
     * T24) — update() SQL brut, même technique que
     * VtcRideOverviewTest::backdateConfirmedAtTo().
     */
    private function backdateInvoiceIssuedAtTo(Invoice $invoice, \Carbon\Carbon $date): void
    {
        DB::table('invoices')->where('id', $invoice->id)->update(['issued_at' => $date->toDateString()]);
    }

    private function backdateCreditNoteIssuedAtTo(CreditNote $creditNote, \Carbon\Carbon $date): void
    {
        DB::table('credit_notes')->where('id', $creditNote->id)->update(['issued_at' => $date->toDateString()]);
    }

    /**
     * Chantier "réconciliation avoirs" (D1/D6/D7) — facture à 2 lignes
     * (TVA 20%, déjà configurée en setUp) : 40 € HT -> 48 € TTC, 20 €
     * HT -> 24 € TTC (total facture = 72 € TTC), pour pouvoir créditer
     * une seule ligne à la fois.
     */
    private function makeInvoiceWithTwoLines(): Invoice
    {
        $customer = Customer::create([
            'name' => 'Client Reconciliation Widget',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);

        $shipped = [];
        foreach ([40, 20] as $unitPriceHt) {
            $product = Product::create([
                'reference' => 'REF-'.uniqid(),
                'nom' => 'Maillot Reconciliation Widget',
                'categorie' => 'Maillots',
                'type' => 'Player Version',
                'taille' => 'M',
                'stock' => 100,
                'prix_achat' => 10,
                'prix_vente' => $unitPriceHt,
            ]);
            $item = SalesOrderItem::create([
                'sales_order_id' => $order->id,
                'product_id' => $product->id,
                'quantity_ordered' => 1,
                'unit_price' => $unitPriceHt,
            ]);
            $shipped[$item->id] = 1;
        }

        $order->markAsConfirmed();
        $order->fresh()->ship($shipped);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    /*
     * =================================================================
     * canView() — même règle d'accès que InvoiceResource/CreditNoteResource
     * (BlocksChauffeurReadAccess : tout le monde sauf un chauffeur)
     * =================================================================
     */

    public function test_le_widget_est_visible_pour_un_admin(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->assertTrue(CommercialOverview::canView());
    }

    public function test_le_widget_est_visible_pour_un_manager(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->assertTrue(CommercialOverview::canView());
    }

    public function test_le_widget_est_visible_pour_un_viewer(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->assertTrue(CommercialOverview::canView());
    }

    public function test_le_widget_est_invisible_pour_un_chauffeur(): void
    {
        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur Reporting', 'user_id' => $user->id]);
        $this->actingAs($user);

        $this->assertFalse(CommercialOverview::canView());
    }

    /*
     * =================================================================
     * Agrégats — montants et compteurs corrects
     * =================================================================
     */

    public function test_le_ca_ttc_et_le_nombre_de_factures_additionnent_plusieurs_factures(): void
    {
        // 100 HT + 20% = 120 TTC, et 50 HT + 20% = 60 TTC -> 180 TTC, 2 factures.
        $this->makeInvoice(100, 1, '-A');
        $this->makeInvoice(50, 1, '-B');

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('180,00 €')
            ->assertSee('2');
    }

    /**
     * Chantier "facturation légale VTC" (D10, validé) — LE test critique
     * de ce chantier pour le reporting : une facture VTC ne doit JAMAIS
     * apparaître dans ce widget, ni dans le CA facturé, ni dans le
     * nombre de factures, ni dans l'encaissé (si un paiement est
     * enregistré dessus). Sans le filtre whereNotNull('sales_order_id')
     * ajouté par ce chantier, ce test échouerait silencieusement en
     * additionnant les deux montants au lieu de n'en garder qu'un.
     */
    public function test_une_facture_vtc_napparait_jamais_dans_le_reporting_commercial(): void
    {
        // Facture vente : 100 HT + 20% = 120 TTC.
        $salesInvoice = $this->makeInvoice(100, 1, '-VTC-ISOL');
        // Facture VTC : 200 HT + 10% = 220 TTC — montant très différent,
        // pour qu'une fuite soit immédiatement visible dans les totaux.
        $vtcInvoice = $this->makeVtcInvoice(200);
        InvoicePayment::recordFor($vtcInvoice, 220, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            // CA facturé total = 120 € (vente uniquement), jamais 340 €
            // (120 + 220) si la facture VTC avait fuité.
            ->assertSee('120,00 €')
            ->assertDontSee('340,00 €')
            ->assertDontSee('220,00 €');
    }

    public function test_le_montant_des_avoirs_est_correctement_additionne(): void
    {
        // Facture 100 HT -> 120 TTC, avoir total -> 120 TTC d'avoir.
        $invoice = $this->makeInvoice(100);
        CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour produit', CreditNote::SETTLEMENT_REFUND);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('120,00 €'); // apparaît à la fois en CA facturé et en avoirs
    }

    public function test_le_ca_net_deduit_bien_les_avoirs_du_ca_facture(): void
    {
        // Facture 100 HT -> 120 TTC, avoir partiel sur une ligne de 20 HT -> 24 TTC d'avoir.
        // CA net attendu : 120 - 24 = 96,00 €.
        $invoice = $this->makeInvoice(100);
        // Un avoir partiel nécessite au moins 2 lignes : on ajoute une
        // seconde ligne factice via une seconde commande facturée sur le
        // même client n'est pas nécessaire ici — un avoir TOTAL sur une
        // facture à une seule ligne suffit à prouver le calcul du CA net.
        CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('0,00 €'); // CA net = 120 - 120 = 0
    }

    /**
     * Un avoir émis ne doit jamais faire varier le compteur "nombre de
     * factures" — les deux indicateurs restent strictement indépendants.
     */
    public function test_un_avoir_ne_modifie_pas_le_nombre_de_factures(): void
    {
        $invoice = $this->makeInvoice(100);
        CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('Nombre de factures (total)')
            ->assertSee('1'); // une seule facture, malgré l'avoir
    }

    /*
     * =================================================================
     * Bornes de période — basées sur issued_at
     * =================================================================
     */

    public function test_une_facture_du_mois_dernier_nest_pas_comptee_dans_ce_mois_mais_lest_dans_le_total(): void
    {
        $invoiceOldMonth = $this->makeInvoice(100, 1, '-old'); // 120 TTC
        $this->backdateInvoiceIssuedAtTo($invoiceOldMonth, now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $invoiceThisMonth = $this->makeInvoice(50, 1, '-new'); // 60 TTC

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(CommercialOverview::class)
            // Total : 120 + 60 = 180,00 €.
            ->assertSee('180,00 €')
            // Ce mois : seulement 60,00 €.
            ->assertSee('60,00 €');
    }

    public function test_un_avoir_du_mois_dernier_nest_compte_que_dans_le_total(): void
    {
        $invoice = $this->makeInvoice(100); // 120 TTC
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        $this->backdateCreditNoteIssuedAtTo($creditNote, now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $component = Livewire::test(CommercialOverview::class);

        // "Ce mois" : aucun avoir (backdaté au mois dernier) -> CA net
        // = CA facturé de ce mois = 0 (aucune facture ce mois-ci).
        // "Total" : avoir de 120,00 € bien compté.
        $component->assertSee('120,00 €');
    }

    /*
     * =================================================================
     * Chantier A — "Encaissé"/"Restant dû" (D1-D4 validés)
     * =================================================================
     */

    public function test_encaisse_additionne_les_paiements_sur_plusieurs_factures(): void
    {
        // Facture A 120 TTC payée intégralement, facture B 60 TTC payée
        // partiellement (30) -> Encaissé (total) = 120 + 30 = 150,00 €.
        $invoiceA = $this->makeInvoice(100, 1, '-A'); // 120 TTC
        $invoiceB = $this->makeInvoice(50, 1, '-B');  // 60 TTC
        InvoicePayment::recordFor($invoiceA, 120, now()->toDateString());
        InvoicePayment::recordFor($invoiceB, 30, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('Encaissé (total)')
            ->assertSee('150,00 €');
    }

    public function test_restant_du_correspond_au_solde_non_encore_paye_sur_les_factures_de_la_periode(): void
    {
        // Facture 120 TTC, paiement partiel de 50 -> Restant dû = 70,00 €.
        $invoice = $this->makeInvoice(100); // 120 TTC
        InvoicePayment::recordFor($invoice, 50, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('Restant dû (total)')
            ->assertSee('70,00 €');
    }

    public function test_une_facture_sans_aucun_paiement_a_un_restant_du_egal_a_son_total_ttc(): void
    {
        $this->makeInvoice(100); // 120 TTC, aucun paiement.

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('Encaissé (total)')
            ->assertSee('0,00 €') // rien d'encaissé
            ->assertSee('120,00 €'); // restant dû = total_ttc intégral
    }

    public function test_une_facture_integralement_payee_a_un_restant_du_de_zero(): void
    {
        $invoice = $this->makeInvoice(100); // 120 TTC
        InvoicePayment::recordFor($invoice, 120, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('Restant dû (total)')
            ->assertSee('0,00 €');
    }

    /**
     * D1 — "Encaissé" est ancré sur paid_at, jamais sur issued_at de la
     * facture : un paiement reçu ce mois-ci sur une facture émise ce
     * mois-ci, mais dont le paid_at est backdaté au mois dernier,
     * compte dans "Encaissé (mois dernier)", pas dans "Encaissé (ce
     * mois)" — alors que D3 continue de scoper "Restant dû (ce mois)"
     * sur la date d'ÉMISSION de la facture (ce mois), et retombe donc à
     * zéro puisque le paiement (quelle que soit sa propre date) couvre
     * déjà tout le solde.
     */
    public function test_encaisse_est_ancre_sur_la_date_du_paiement_pas_sur_la_date_de_la_facture(): void
    {
        $invoice = $this->makeInvoice(100); // 120 TTC, émise ce mois-ci.
        InvoicePayment::recordFor(
            $invoice,
            120,
            now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateString(),
        );

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $component = Livewire::test(CommercialOverview::class);

        $component->assertSee('Encaissé (mois dernier)');
        $component->assertSee('Encaissé (ce mois)');
        // "Restant dû (ce mois)" = 0 : la facture (émise ce mois) est
        // intégralement couverte, peu importe la date du paiement.
        $component->assertSee('Restant dû (ce mois)');
        $component->assertSee('0,00 €');
    }

    /**
     * Non-régression — les 4 indicateurs déjà existants avant le
     * chantier A ne doivent jamais être affectés par l'existence d'un
     * paiement.
     */
    public function test_les_4_indicateurs_existants_restent_inchanges_en_presence_dun_paiement(): void
    {
        $invoice = $this->makeInvoice(100); // 120 TTC
        InvoicePayment::recordFor($invoice, 50, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('120,00 €') // CA TTC facturé : inchangé par le paiement
            ->assertSee('Nombre de factures (total)')
            ->assertSee('1'); // toujours 1 facture, jamais affecté par un paiement
    }

    /*
     * =================================================================
     * Chantier "réconciliation avoirs" (D1/D6/D7, validés) — "Restant
     * dû" retranche désormais aussi les avoirs, avec le même ancrage
     * temporel (D7) que les paiements : les avoirs comptés sont ceux
     * liés aux factures ÉMISES pendant la période, jamais filtrés sur
     * la date d'émission de l'avoir lui-même (contrairement à "Montant
     * des avoirs", qui reste volontairement ancrée sur l'avoir).
     * =================================================================
     */

    public function test_restant_du_deduit_un_avoir_emis_pendant_la_periode(): void
    {
        // Facture 120 TTC émise ce mois, avoir total émis ce mois aussi
        // -> net = 0, aucun paiement -> Restant dû = 0,00 €.
        $invoice = $this->makeInvoice(100); // 120 TTC, ce mois
        CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('Restant dû (ce mois)')
            ->assertSee('0,00 €');

        $this->assertSame(0.0, $invoice->fresh()->amountRemaining());
    }

    /**
     * D7 (validé) — cas explicitement demandé : un avoir dont la date
     * d'émission PROPRE tombe APRÈS la fin de la période, mais qui
     * porte sur une facture émise PENDANT la période, doit malgré tout
     * être déduit de "Restant dû" de cette période (ancrage sur la
     * facture, jamais sur l'avoir) — alors que "Montant des avoirs" de
     * cette même période reste volontairement à 0 (ancrage sur
     * l'avoir), ces deux tuiles n'ayant jamais le même ancrage.
     */
    public function test_avoir_emis_apres_la_periode_est_exclu_du_montant_des_avoirs_mais_inclus_dans_le_restant_du(): void
    {
        $invoice = $this->makeInvoice(100); // 120 TTC, émise ce mois
        $creditNote = CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);
        // Avoir daté du mois PROCHAIN : après la fin de "ce mois".
        $this->backdateCreditNoteIssuedAtTo($creditNote, now()->addMonthNoOverflow()->startOfMonth());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $component = Livewire::test(CommercialOverview::class);
        $component->assertSee('Montant des avoirs (ce mois)');
        $component->assertSee('Restant dû (ce mois)');
        $component->assertSee('0,00 €');

        // Vérification numérique non ambiguë via le modèle : l'avoir
        // (120 €) est bien pris en compte dans le solde, peu importe sa
        // propre date d'émission (D7).
        $this->assertSame(0.0, $invoice->fresh()->amountRemaining());
        $this->assertSame(120.0, $invoice->fresh()->creditedAmount());
    }

    /**
     * Bornes de période (D7) — un avoir sur une facture DU MOIS DERNIER
     * ne doit jamais affecter le "Restant dû" de CE MOIS ; une facture
     * SANS AUCUN avoir garde son "Restant dû" intact.
     */
    public function test_le_restant_du_du_mois_dernier_nest_pas_affecte_par_un_avoir_sur_une_facture_de_ce_mois(): void
    {
        // Facture A émise CE MOIS (120 TTC), avoir total dessus -> 0 dû.
        $invoiceThisMonth = $this->makeInvoice(100, 1, '-this');
        CreditNote::generateFromInvoice($invoiceThisMonth, $invoiceThisMonth->lines->pluck('id')->all(), 'Retour', CreditNote::SETTLEMENT_REFUND);

        // Facture B émise LE MOIS DERNIER (60 TTC), SANS AUCUN avoir.
        $invoiceLastMonth = $this->makeInvoice(50, 1, '-last');
        $this->backdateInvoiceIssuedAtTo($invoiceLastMonth, now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(CommercialOverview::class)
            ->assertSee('Restant dû (mois dernier)')
            ->assertSee('60,00 €') // facture B intégrale, aucun avoir dessus
            ->assertSee('Restant dû (ce mois)')
            ->assertSee('0,00 €'); // facture A soldée par avoir

        $this->assertSame(60.0, $invoiceLastMonth->fresh()->amountRemaining());
        $this->assertSame(0.0, $invoiceThisMonth->fresh()->amountRemaining());
    }

    /**
     * Vérification demandée explicitement : le modèle Invoice
     * (amountRemaining()), le filtre InvoicesTable (payment_status) et
     * l'agrégat CommercialOverview ("Restant dû") doivent donner
     * EXACTEMENT le même résultat sur une facture avec avoir ET
     * paiement combinés (D6 : 3 implémentations indépendantes mais
     * jamais divergentes).
     */
    public function test_le_modele_le_filtre_et_le_widget_saccordent_sur_le_restant_du_avec_avoir_et_paiement(): void
    {
        // Facture 72 TTC (2 lignes : 48 + 24). Avoir sur la ligne de
        // 48 € -> net = 24 €. Paiement de 10 € (partiel du net).
        // Reste dû attendu : 72 - 48 - 10 = 14 €.
        $invoice = $this->makeInvoiceWithTwoLines();
        CreditNote::generateFromInvoice($invoice, [$invoice->lines->first()->id], 'Retour', CreditNote::SETTLEMENT_REFUND);
        InvoicePayment::recordFor($invoice->fresh(), 10, now()->toDateString());

        $freshInvoice = $invoice->fresh();

        // 1) Modèle.
        $this->assertSame(14.0, $freshInvoice->amountRemaining());
        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $freshInvoice->paymentStatus());

        // 2) Filtre InvoicesTable : classée "partiellement payée",
        // jamais "payée" (72 brut jamais utilisé), jamais "non payée".
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(ListInvoices::class)
            ->filterTable('payment_status', Invoice::PAYMENT_STATUS_PARTIAL)
            ->assertCanSeeTableRecords([$freshInvoice]);

        Livewire::test(ListInvoices::class)
            ->filterTable('payment_status', Invoice::PAYMENT_STATUS_PAID)
            ->assertCanNotSeeTableRecords([$freshInvoice]);

        // 3) Widget : "Restant dû (total)" affiche le même montant net
        // (14,00 €) que le modèle en (1).
        Livewire::test(CommercialOverview::class)
            ->assertSee('Restant dû (total)')
            ->assertSee('14,00 €');
    }
}
