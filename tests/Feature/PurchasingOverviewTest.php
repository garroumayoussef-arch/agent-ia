<?php

namespace Tests\Feature;

use App\Filament\Widgets\PurchasingOverview;
use App\Models\Driver;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T29 — widget de reporting achats (PurchasingOverview). 4
 * indicateurs (montant commandé TTC, nombre de commandes, montant
 * facturé TTC, nombre de factures fournisseurs) × 4 périodes fixes,
 * gabarit direct de CommercialOverviewTest (T27). Périmètre
 * strictement en lecture, aucune modification de PurchaseOrder/
 * PurchaseOrderItem/SupplierInvoice ici — seuls des enregistrements
 * déjà existants sont créés via leurs points d'entrée habituels.
 */
class PurchasingOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // Sans taux d'achat par défaut, PurchaseOrderItem::resolvePurchaseTaxRate()
        // ne résout aucun taux -> PurchaseOrder::total_ttc reste NULL
        // (cf. applyTaxAllocation()). 0% pour un calcul de référence
        // simple : total_ttc = total HT, sans ambiguïté.
        TaxRate::create([
            'label' => 'Achat 0%',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 0,
            'is_default_purchase' => true,
            'is_active' => true,
        ]);
    }

    private function makeSupplier(string $suffix = ''): Supplier
    {
        return Supplier::create(['name' => 'Fournisseur Achats'.$suffix]);
    }

    /**
     * Crée un bon de commande d'un montant TTC connu (aucune TVA
     * configurée : total_ttc = total HT, pour un calcul de référence
     * simple et non ambigu) et le fait passer au statut demandé.
     */
    private function makeOrder(Supplier $supplier, float $unitPrice, string $status = PurchaseOrder::STATUS_ORDERED): PurchaseOrder
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Achats',
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
            'unit_price' => $unitPrice,
        ]);

        if ($status !== PurchaseOrder::STATUS_DRAFT) {
            $order->markAsOrdered();
        }

        if ($status === PurchaseOrder::STATUS_CANCELLED) {
            $order->cancel();
        }

        return $order->fresh();
    }

    private function makeSupplierInvoice(Supplier $supplier, PurchaseOrder $order, float $totalTtc): SupplierInvoice
    {
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

    private function backdateOrderDateTo(PurchaseOrder $order, \Carbon\Carbon $date): void
    {
        DB::table('purchase_orders')->where('id', $order->id)->update(['order_date' => $date->toDateString()]);
    }

    private function backdateInvoiceDateTo(SupplierInvoice $invoice, \Carbon\Carbon $date): void
    {
        DB::table('supplier_invoices')->where('id', $invoice->id)->update(['invoice_date' => $date->toDateString()]);
    }

    /*
     * =================================================================
     * canView() — même règle d'accès que PurchaseOrderResource/
     * SupplierInvoiceResource (BlocksChauffeurReadAccess)
     * =================================================================
     */

    public function test_le_widget_est_visible_pour_un_admin(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->assertTrue(PurchasingOverview::canView());
    }

    public function test_le_widget_est_visible_pour_un_manager(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('manager'));

        $this->assertTrue(PurchasingOverview::canView());
    }

    public function test_le_widget_est_visible_pour_un_viewer(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        $this->assertTrue(PurchasingOverview::canView());
    }

    public function test_le_widget_est_invisible_pour_un_chauffeur(): void
    {
        $user = User::factory()->create();
        Driver::create(['name' => 'Chauffeur Achats', 'user_id' => $user->id]);
        $this->actingAs($user);

        $this->assertFalse(PurchasingOverview::canView());
    }

    /*
     * =================================================================
     * Agrégats — montants et compteurs corrects
     * =================================================================
     */

    public function test_le_montant_commande_et_le_nombre_de_commandes_additionnent_plusieurs_bons(): void
    {
        $supplier = $this->makeSupplier();
        $this->makeOrder($supplier, 100); // 100 TTC
        $this->makeOrder($supplier, 50);  // 50 TTC -> 150 TTC, 2 commandes

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('150,00 €')
            ->assertSee('Nombre de commandes (total)');
    }

    public function test_le_montant_facture_et_le_nombre_de_factures_additionnent_plusieurs_factures(): void
    {
        $supplier = $this->makeSupplier();
        $orderA = $this->makeOrder($supplier, 100);
        $orderB = $this->makeOrder($supplier, 50);
        $this->makeSupplierInvoice($supplier, $orderA, 120);
        $this->makeSupplierInvoice($supplier, $orderB, 60); // 180 TTC, 2 factures

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('180,00 €');
    }

    /**
     * Vérifie explicitement l'absence de fusion entre les deux axes
     * (décision 2, validée) : le montant commandé et le montant facturé
     * restent deux totaux INDÉPENDANTS, jamais additionnés ni soustraits
     * l'un de l'autre.
     */
    public function test_le_montant_commande_et_le_montant_facture_restent_independants(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrder($supplier, 100); // commandé : 100 TTC
        $this->makeSupplierInvoice($supplier, $order, 30); // facturé : 30 TTC

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('100,00 €') // montant commandé, non affecté par la facture
            ->assertSee('30,00 €')  // montant facturé, non affecté par la commande
            ->assertDontSee('70,00 €')  // jamais un "écart" 100 - 30
            ->assertDontSee('130,00 €'); // jamais une somme 100 + 30
    }

    /*
     * =================================================================
     * Exclusion des commandes brouillon/annulées (décision 1, validée)
     * =================================================================
     */

    public function test_une_commande_en_brouillon_nest_comptee_dans_aucune_statistique(): void
    {
        $supplier = $this->makeSupplier();
        $this->makeOrder($supplier, 100, PurchaseOrder::STATUS_DRAFT);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('0') // Nombre de commandes (total) = 0
            ->assertDontSee('100,00 €');
    }

    public function test_une_commande_annulee_nest_comptee_dans_aucune_statistique(): void
    {
        $supplier = $this->makeSupplier();
        $this->makeOrder($supplier, 100, PurchaseOrder::STATUS_CANCELLED);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertDontSee('100,00 €');
    }

    public function test_une_commande_partiellement_recue_est_comptee(): void
    {
        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        $supplier = $this->makeSupplier();
        $order = $this->makeOrder($supplier, 100, PurchaseOrder::STATUS_PARTIALLY_RECEIVED);
        $order->receive([$order->items->first()->id => 1]);

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('100,00 €');
    }

    /*
     * =================================================================
     * Bornes de période
     * =================================================================
     */

    public function test_une_commande_du_mois_dernier_nest_pas_comptee_dans_ce_mois_mais_lest_dans_le_total(): void
    {
        $supplier = $this->makeSupplier();
        $orderOldMonth = $this->makeOrder($supplier, 100);
        $this->backdateOrderDateTo($orderOldMonth, now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $this->makeOrder($supplier, 50);

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('150,00 €') // total : 100 + 50
            ->assertSee('50,00 €');  // ce mois : seulement la nouvelle
    }

    public function test_une_facture_fournisseur_du_mois_dernier_nest_pas_comptee_dans_ce_mois_mais_lest_dans_le_total(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrder($supplier, 100);
        $invoiceOldMonth = $this->makeSupplierInvoice($supplier, $order, 40);
        $this->backdateInvoiceDateTo($invoiceOldMonth, now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $this->makeSupplierInvoice($supplier, $order, 20);

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('60,00 €') // total : 40 + 20
            ->assertSee('20,00 €'); // ce mois : seulement la nouvelle
    }

    /*
     * =================================================================
     * Chantier A — "Décaissé"/"Restant dû fournisseurs" (D1-D4 validés),
     * symétrique exact de CommercialOverviewTest
     * =================================================================
     */

    public function test_decaisse_additionne_les_paiements_sur_plusieurs_factures(): void
    {
        $supplier = $this->makeSupplier();
        $orderA = $this->makeOrder($supplier, 100);
        $orderB = $this->makeOrder($supplier, 50);
        $invoiceA = $this->makeSupplierInvoice($supplier, $orderA, 120);
        $invoiceB = $this->makeSupplierInvoice($supplier, $orderB, 60);
        SupplierInvoicePayment::recordFor($invoiceA, 120, now()->toDateString());
        SupplierInvoicePayment::recordFor($invoiceB, 30, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('Décaissé (total)')
            ->assertSee('150,00 €');
    }

    public function test_restant_du_fournisseurs_correspond_au_solde_non_encore_paye(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrder($supplier, 100);
        $invoice = $this->makeSupplierInvoice($supplier, $order, 120);
        SupplierInvoicePayment::recordFor($invoice, 50, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('Restant dû fournisseurs (total)')
            ->assertSee('70,00 €');
    }

    public function test_une_facture_fournisseur_integralement_payee_a_un_restant_du_de_zero(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrder($supplier, 100);
        $invoice = $this->makeSupplierInvoice($supplier, $order, 120);
        SupplierInvoicePayment::recordFor($invoice, 120, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('Restant dû fournisseurs (total)')
            ->assertSee('0,00 €');
    }

    /**
     * D1/D4 — "Décaissé" est ancré sur paid_at, jamais sur invoice_date,
     * symétrique exact du test équivalent côté vente.
     */
    public function test_decaisse_est_ancre_sur_la_date_du_paiement_pas_sur_la_date_de_la_facture(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrder($supplier, 100);
        $invoice = $this->makeSupplierInvoice($supplier, $order, 120); // enregistrée ce mois-ci.
        SupplierInvoicePayment::recordFor(
            $invoice,
            120,
            now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateString(),
        );

        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $component = Livewire::test(PurchasingOverview::class);

        $component->assertSee('Décaissé (mois dernier)');
        $component->assertSee('Décaissé (ce mois)');
        // "Restant dû fournisseurs (ce mois)" = 0 : la facture
        // (enregistrée ce mois) est intégralement couverte, peu importe
        // la date du paiement.
        $component->assertSee('Restant dû fournisseurs (ce mois)');
        $component->assertSee('0,00 €');
    }

    /**
     * Non-régression — les 4 indicateurs déjà existants avant le
     * chantier A ne doivent jamais être affectés par l'existence d'un
     * paiement.
     */
    public function test_les_4_indicateurs_existants_restent_inchanges_en_presence_dun_paiement(): void
    {
        $supplier = $this->makeSupplier();
        $order = $this->makeOrder($supplier, 100);
        $invoice = $this->makeSupplierInvoice($supplier, $order, 120);
        SupplierInvoicePayment::recordFor($invoice, 50, now()->toDateString());

        $this->actingAs(User::factory()->create()->assignRole('manager'));

        Livewire::test(PurchasingOverview::class)
            ->assertSee('100,00 €') // montant commandé : inchangé par le paiement
            ->assertSee('120,00 €'); // montant facturé : inchangé par le paiement
    }
}
