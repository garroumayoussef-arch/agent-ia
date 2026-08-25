<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Étape T23 — génération de facture (Invoice::generateFromSalesOrder()).
 * Couvre D1 (SIREN B2B), D2 (statut shipped strict), D3 (date de vente
 * = dernière expédition), D5 (régime TVA jamais supposé), l'immuabilité
 * et la numérotation séquentielle.
 */
class InvoiceGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create([
            'name' => 'Entrepôt par défaut',
            'code' => 'defaut',
            'is_default' => true,
        ]);

        TaxRate::create([
            'label' => 'TVA 20%',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 20,
            'is_default_sale' => true,
            'is_active' => true,
        ]);
    }

    private function configureCompanySettings(array $overrides = []): CompanySettings
    {
        $settings = CompanySettings::current();
        $settings->update(array_merge([
            'legal_name' => 'Magarrou',
            'legal_form' => 'SASU',
            'address' => '1 rue du Sport',
            'postal_code' => '75000',
            'city' => 'Paris',
            'country' => 'France',
            'siren' => '111222333',
            'siret' => '11122233300010',
            'rcs_city' => 'Paris',
            'vat_regime' => CompanySettings::VAT_REGIME_STANDARD,
            'vat_number' => 'FR11111222333',
            'recovery_indemnity_amount' => 40,
        ], $overrides));

        return $settings->fresh();
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot T23',
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
     * Crée une commande, la confirme, l'expédie intégralement en un
     * seul appel ship() (pas de partiel).
     */
    private function makeShippedSalesOrder(Customer $customer, Product $product, int $quantity = 5, float $unitPrice = 20): SalesOrder
    {
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => $quantity,
            'unit_price' => $unitPrice,
        ]);
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => $quantity]);

        return $order->fresh();
    }

    /*
     * =================================================================
     * D2 / contrainte 7 — statut shipped strictement requis
     * =================================================================
     */

    public function test_la_generation_est_refusee_pour_une_commande_partiellement_expediee(): void
    {
        $this->configureCompanySettings();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $order = SalesOrder::create(['reference' => 'CMD-PARTIEL', 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 10,
            'unit_price' => 20,
        ]);
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 4]); // partiel : 4/10

        $order->refresh();
        $this->assertSame(SalesOrder::STATUS_PARTIALLY_SHIPPED, $order->status);

        $this->expectException(\Exception::class);

        try {
            Invoice::generateFromSalesOrder($order);
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }

    public function test_la_generation_reussit_pour_une_commande_integralement_expediee(): void
    {
        $this->configureCompanySettings();
        $order = $this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct());

        $invoice = Invoice::generateFromSalesOrder($order);

        $this->assertSame(1, Invoice::count());
        $this->assertSame($order->id, $invoice->sales_order_id);
    }

    public function test_une_seule_facture_par_commande(): void
    {
        $this->configureCompanySettings();
        $order = $this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct());

        Invoice::generateFromSalesOrder($order);

        $this->expectException(\Exception::class);

        try {
            Invoice::generateFromSalesOrder($order->fresh());
        } finally {
            $this->assertSame(1, Invoice::count());
        }
    }

    /*
     * =================================================================
     * D5 — régime TVA jamais supposé
     * =================================================================
     */

    public function test_la_generation_est_refusee_si_lentreprise_nest_pas_configuree(): void
    {
        // Aucun configureCompanySettings() : CompanySettings::current()
        // reste entièrement vide (tous champs NULL).
        $order = $this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct());

        $this->expectException(\Exception::class);

        try {
            Invoice::generateFromSalesOrder($order);
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }

    public function test_la_generation_est_refusee_si_regime_standard_sans_numero_de_tva(): void
    {
        $this->configureCompanySettings(['vat_regime' => CompanySettings::VAT_REGIME_STANDARD, 'vat_number' => null]);
        $order = $this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct());

        $this->expectException(\Exception::class);
        Invoice::generateFromSalesOrder($order);
    }

    public function test_la_generation_reussit_en_franchise_en_base_avec_mention_configuree(): void
    {
        $this->configureCompanySettings([
            'vat_regime' => CompanySettings::VAT_REGIME_FRANCHISE,
            'vat_number' => null,
            'vat_exemption_mention' => 'TVA non applicable, article 293 B du CGI.',
        ]);
        $order = $this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct());

        $invoice = Invoice::generateFromSalesOrder($order);

        $this->assertSame('TVA non applicable, article 293 B du CGI.', $invoice->vat_exemption_mention_snapshot);
    }

    public function test_la_generation_est_refusee_en_franchise_en_base_sans_mention_configuree(): void
    {
        $this->configureCompanySettings([
            'vat_regime' => CompanySettings::VAT_REGIME_FRANCHISE,
            'vat_number' => null,
            'vat_exemption_mention' => null,
        ]);
        $order = $this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct());

        $this->expectException(\Exception::class);
        Invoice::generateFromSalesOrder($order);
    }

    /*
     * =================================================================
     * D1 / contrainte 8 — SIREN B2B requis
     * =================================================================
     */

    public function test_generation_b2c_reussit_sans_siren(): void
    {
        $this->configureCompanySettings();
        $customer = $this->makeCustomer(['customer_type' => Customer::TYPE_INDIVIDUAL]);
        $order = $this->makeShippedSalesOrder($customer, $this->makeProduct());

        $invoice = Invoice::generateFromSalesOrder($order);

        $this->assertSame('individual', $invoice->customer_type);
        $this->assertSame('b2c_domestic', $invoice->transaction_type);
    }

    public function test_generation_b2b_est_refusee_sans_siren(): void
    {
        $this->configureCompanySettings();
        $customer = $this->makeCustomer(['customer_type' => Customer::TYPE_BUSINESS, 'siren' => null]);
        $order = $this->makeShippedSalesOrder($customer, $this->makeProduct());

        $this->expectException(\Exception::class);

        try {
            Invoice::generateFromSalesOrder($order);
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }

    public function test_generation_b2b_reussit_avec_siren(): void
    {
        $this->configureCompanySettings(['country' => 'France']);
        $customer = $this->makeCustomer([
            'customer_type' => Customer::TYPE_BUSINESS,
            'siren' => '987654321',
            'country' => 'France',
        ]);
        $order = $this->makeShippedSalesOrder($customer, $this->makeProduct());

        $invoice = Invoice::generateFromSalesOrder($order);

        $this->assertSame('987654321', $invoice->customer_siren);
        $this->assertSame('b2b_domestic', $invoice->transaction_type);
    }

    public function test_transaction_b2b_intracommunautaire_si_pays_different(): void
    {
        $this->configureCompanySettings(['country' => 'France']);
        $customer = $this->makeCustomer([
            'customer_type' => Customer::TYPE_BUSINESS,
            'siren' => '987654321',
            'country' => 'Belgique',
        ]);
        $order = $this->makeShippedSalesOrder($customer, $this->makeProduct());

        $invoice = Invoice::generateFromSalesOrder($order);

        $this->assertSame('b2b_intracommunity', $invoice->transaction_type);
    }

    /*
     * =================================================================
     * D3 — date de vente/prestation = dernière expédition liée
     * =================================================================
     */

    public function test_la_date_de_vente_correspond_a_la_derniere_expedition_pas_a_la_date_de_commande(): void
    {
        $this->configureCompanySettings();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        Carbon::setTestNow(Carbon::parse('2026-01-10'));
        $order = SalesOrder::create(['reference' => 'CMD-DATE', 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 10,
            'unit_price' => 20,
        ]);
        $order->markAsConfirmed();

        // Expédition partielle le 15/01, puis expédition finale le
        // 20/01 : la date de vente doit correspondre à CETTE DERNIÈRE
        // expédition, jamais à la date de commande (10/01) ni à la date
        // de la première expédition partielle (15/01).
        Carbon::setTestNow(Carbon::parse('2026-01-15'));
        $order->fresh()->ship([$item->id => 4]);

        Carbon::setTestNow(Carbon::parse('2026-01-20'));
        $order->fresh()->ship([$item->id => 6]);

        Carbon::setTestNow(); // restaure l'horloge réelle

        $order->refresh();
        $this->assertSame(SalesOrder::STATUS_SHIPPED, $order->status);

        $invoice = Invoice::generateFromSalesOrder($order);

        $this->assertSame('2026-01-20', $invoice->sale_completed_at->toDateString());
    }

    /*
     * =================================================================
     * Numérotation (D6)
     * =================================================================
     */

    public function test_les_numeros_de_facture_sont_sequentiels(): void
    {
        $this->configureCompanySettings();
        $product = $this->makeProduct();

        $invoice1 = Invoice::generateFromSalesOrder($this->makeShippedSalesOrder($this->makeCustomer(), $product));
        $invoice2 = Invoice::generateFromSalesOrder($this->makeShippedSalesOrder($this->makeCustomer(), $product));

        $this->assertNotSame($invoice1->number, $invoice2->number);
        $this->assertTrue($invoice2->number > $invoice1->number);
        $this->assertStringStartsWith('FA-', $invoice1->number);
    }

    /*
     * =================================================================
     * Immuabilité (contrainte 5)
     * =================================================================
     */

    public function test_une_facture_emise_ne_peut_pas_etre_modifiee(): void
    {
        $this->configureCompanySettings();
        $invoice = Invoice::generateFromSalesOrder($this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct()));

        $this->expectException(\Exception::class);
        $invoice->update(['total_ttc' => 999]);
    }

    public function test_une_facture_emise_ne_peut_pas_etre_supprimee(): void
    {
        $this->configureCompanySettings();
        $invoice = Invoice::generateFromSalesOrder($this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct()));

        $this->expectException(\Exception::class);
        $invoice->delete();
    }

    public function test_une_ligne_de_facture_emise_ne_peut_pas_etre_modifiee(): void
    {
        $this->configureCompanySettings();
        $invoice = Invoice::generateFromSalesOrder($this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct()));
        $line = $invoice->lines()->first();

        $this->expectException(\Exception::class);
        $line->update(['quantity' => 999]);
    }

    /**
     * Symétrique du test précédent, jamais couvert jusqu'ici : la
     * suppression d'une ligne de facture émise doit être bloquée au
     * même titre que sa modification (InvoiceLine::booted()/deleting()).
     */
    public function test_une_ligne_de_facture_emise_ne_peut_pas_etre_supprimee(): void
    {
        $this->configureCompanySettings();
        $invoice = Invoice::generateFromSalesOrder($this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct()));
        $line = $invoice->lines()->first();

        $this->expectException(\Exception::class);

        try {
            $line->delete();
        } finally {
            // La ligne doit rester présente en base malgré la tentative.
            $this->assertSame(1, $invoice->lines()->count());
        }
    }

    /**
     * Preuve directe de l'immuabilité "snapshot" : modifier le client
     * APRÈS émission ne doit jamais changer une facture déjà émise.
     */
    public function test_modifier_le_client_apres_emission_ne_change_pas_la_facture_deja_emise(): void
    {
        $this->configureCompanySettings();
        $customer = $this->makeCustomer(['name' => 'Nom Original']);
        $invoice = Invoice::generateFromSalesOrder($this->makeShippedSalesOrder($customer, $this->makeProduct()));

        $customer->update(['name' => 'Nom Modifié Après Facture']);

        $this->assertSame('Nom Original', $invoice->fresh()->customer_name);
    }

    /*
     * =================================================================
     * Lignes de facture
     * =================================================================
     */

    public function test_les_lignes_de_facture_reprennent_les_montants_figes_de_la_commande(): void
    {
        $this->configureCompanySettings();
        $order = $this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct(), quantity: 3, unitPrice: 15);
        $orderItem = $order->items()->first();

        $invoice = Invoice::generateFromSalesOrder($order);
        $line = $invoice->lines()->first();

        $this->assertSame(3, $line->quantity);
        $this->assertEqualsWithDelta((float) $orderItem->unit_price, (float) $line->unit_price_ht, 0.001);
        $this->assertEqualsWithDelta((float) $orderItem->subtotal, (float) $line->subtotal_ht, 0.001);
    }

    /*
     * =================================================================
     * Catégorie d'opération (préparation e-invoicing)
     * =================================================================
     */

    public function test_la_categorie_operation_est_vente_par_defaut(): void
    {
        $this->configureCompanySettings();
        $invoice = Invoice::generateFromSalesOrder($this->makeShippedSalesOrder($this->makeCustomer(), $this->makeProduct()));

        $this->assertSame('vente', $invoice->operation_category);
    }
}
