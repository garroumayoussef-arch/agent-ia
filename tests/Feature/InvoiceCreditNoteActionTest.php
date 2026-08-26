<?php

namespace Tests\Feature;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Étape T24 — actions "Créer un avoir total"/"Créer un avoir partiel"
 * sur ViewInvoice. Vérifie explicitement qu'elles portent leur propre
 * garde de rôle (InvoiceResource::canEdit()) — un viewer ne doit
 * jamais pouvoir émettre un avoir, même depuis la page de consultation
 * qu'il peut par ailleurs consulter (même choix délibéré que
 * generateInvoiceAction en T23).
 */
class InvoiceCreditNoteActionTest extends TestCase
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
     * Facture à 2 lignes, générée par un manager déjà attribué à
     * l'entrepôt par défaut (T19, D2 fail-closed — nécessaire pour
     * pouvoir expédier).
     */
    private function makeShippedInvoiceAsManager(User $manager): Invoice
    {
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));

        $customer = Customer::create([
            'name' => 'Client Action Avoir',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);

        $shipped = [];
        foreach (['A', 'B'] as $suffix) {
            $product = Product::create([
                'reference' => 'REF-'.uniqid(),
                'nom' => "Maillot Avoir {$suffix}",
                'categorie' => 'Maillots',
                'type' => 'Player Version',
                'taille' => 'M',
                'stock' => 100,
                'prix_achat' => 10,
                'prix_vente' => 20,
            ]);
            $item = SalesOrderItem::create([
                'sales_order_id' => $order->id,
                'product_id' => $product->id,
                'quantity_ordered' => 2,
                'unit_price' => 20,
            ]);
            $shipped[$item->id] = 2;
        }

        $order->markAsConfirmed();
        $order->fresh()->ship($shipped);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    /**
     * Chantier "réconciliation avoirs" (D4) — même principe que
     * makeShippedInvoiceAsManager(), avec un nombre de lignes
     * paramétrable (chaque ligne à 48 € TTC, même composition
     * qté 2 × 20 € + TVA 20% que la méthode ci-dessus) pour les
     * scénarios à plusieurs avoirs successifs.
     */
    private function makeShippedInvoiceWithLineCount(User $manager, int $lineCount): Invoice
    {
        $manager->warehouses()->attach(Warehouse::where('is_default', true)->value('id'));

        $customer = Customer::create([
            'name' => 'Client Avoir D4',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);

        $shipped = [];
        for ($i = 0; $i < $lineCount; $i++) {
            $product = Product::create([
                'reference' => 'REF-'.uniqid(),
                'nom' => "Maillot Avoir D4 {$i}",
                'categorie' => 'Maillots',
                'type' => 'Player Version',
                'taille' => 'M',
                'stock' => 100,
                'prix_achat' => 10,
                'prix_vente' => 20,
            ]);
            $item = SalesOrderItem::create([
                'sales_order_id' => $order->id,
                'product_id' => $product->id,
                'quantity_ordered' => 2,
                'unit_price' => 20,
            ]);
            $shipped[$item->id] = 2;
        }

        $order->markAsConfirmed();
        $order->fresh()->ship($shipped);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    /*
     * =================================================================
     * Chantier "réconciliation avoirs" (D4, validé) — avertissement
     * (jamais un blocage) lorsqu'un avoir laisse un solde créditeur.
     * =================================================================
     */

    public function test_avoir_normal_sans_aucun_paiement_ne_declenche_aucune_alerte(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager); // 2 lignes, 96 € au total

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('generateTotalCreditNote', data: [
                'settlement_type' => CreditNote::SETTLEMENT_REFUND,
                'reason' => 'Avoir total sans paiement',
            ])
            ->assertNotNotified('Solde créditeur généré');

        $this->assertSame(1, CreditNote::where('invoice_id', $invoice->id)->count());
        $this->assertSame(0.0, $invoice->fresh()->creditBalance());
    }

    public function test_avoir_normal_sur_facture_partiellement_payee_ne_declenche_aucune_alerte(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager); // 2 lignes, 96 € au total
        InvoicePayment::recordFor($invoice, 40, now()->toDateString());
        $firstLineId = $invoice->lines->first()->id;

        Livewire::test(ViewInvoice::class, ['record' => $invoice->fresh()->getKey()])
            ->callAction('generatePartialCreditNote', data: [
                'invoice_line_ids' => [$firstLineId],
                'settlement_type' => CreditNote::SETTLEMENT_REFUND,
                'reason' => 'Avoir partiel sur facture partiellement payée',
            ])
            ->assertNotNotified('Solde créditeur généré');

        $this->assertSame(1, CreditNote::where('invoice_id', $invoice->id)->count());
        // net après avoir = 96 − 48 = 48 € ; payé = 40 € ≤ 48 € : aucun
        // solde créditeur.
        $this->assertSame(0.0, $invoice->fresh()->creditBalance());
    }

    public function test_avoir_sur_facture_totalement_payee_declenche_une_alerte_de_solde_crediteur(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager); // 96 € au total
        InvoicePayment::recordFor($invoice, 96, now()->toDateString());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->fresh()->getKey()])
            ->callAction('generateTotalCreditNote', data: [
                'settlement_type' => CreditNote::SETTLEMENT_REFUND,
                'reason' => 'Avoir après paiement intégral',
            ])
            ->assertNotified('Solde créditeur généré');

        $this->assertSame(1, CreditNote::where('invoice_id', $invoice->id)->count());
        $this->assertSame(96.0, $invoice->fresh()->creditBalance());
    }

    public function test_plusieurs_avoirs_successifs_lalerte_napparait_quau_moment_ou_le_solde_crediteur_est_reellement_genere(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceWithLineCount($manager, 3); // 3 lignes, 144 € au total
        InvoicePayment::recordFor($invoice, 90, now()->toDateString());

        $lines = $invoice->fresh()->lines;
        $firstLineId = $lines[0]->id;
        $secondLineId = $lines[1]->id;

        $component = Livewire::test(ViewInvoice::class, ['record' => $invoice->fresh()->getKey()]);

        // Avoir 1 (48 €) : net = 144 − 48 = 96 € ; payé = 90 € ≤ 96 € ->
        // pas d'alerte.
        $component->callAction('generatePartialCreditNote', data: [
            'invoice_line_ids' => [$firstLineId],
            'settlement_type' => CreditNote::SETTLEMENT_REFUND,
            'reason' => 'Premier avoir partiel',
        ])->assertNotNotified('Solde créditeur généré');

        // Avoir 2 (48 €) : net = 144 − 96 = 48 € ; payé = 90 € > 48 € ->
        // alerte (cumul des deux avoirs, jamais visible sur le premier
        // pris isolément).
        $component->callAction('generatePartialCreditNote', data: [
            'invoice_line_ids' => [$secondLineId],
            'settlement_type' => CreditNote::SETTLEMENT_REFUND,
            'reason' => 'Second avoir partiel',
        ])->assertNotified('Solde créditeur généré');

        $this->assertSame(2, CreditNote::where('invoice_id', $invoice->id)->count());
        $this->assertSame(42.0, $invoice->fresh()->creditBalance());
    }

    public function test_lavoir_est_bien_cree_malgre_lalerte_de_solde_crediteur(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager);
        InvoicePayment::recordFor($invoice, 96, now()->toDateString());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->fresh()->getKey()])
            ->callAction('generateTotalCreditNote', data: [
                'settlement_type' => CreditNote::SETTLEMENT_REFUND,
                'reason' => 'Avoir malgré alerte',
            ])
            ->assertNotified('Solde créditeur généré');

        // D4 (validé) : l'avertissement n'a JAMAIS empêché la création.
        $creditNote = CreditNote::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(CreditNote::SCOPE_TOTAL, $creditNote->scope);
        $this->assertCount(2, $creditNote->lines);
        $this->assertTrue(CreditNote::creditableLinesFor($invoice->fresh())->isEmpty());
    }

    public function test_les_deux_actions_sont_invisibles_si_aucune_ligne_nest_creditable(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager);

        // Facture intégralement créditée en amont.
        CreditNote::generateFromInvoice($invoice, $invoice->lines->pluck('id')->all(), 'Tout créditer', CreditNote::SETTLEMENT_REFUND);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('generateTotalCreditNote')
            ->assertActionHidden('generatePartialCreditNote');
    }

    public function test_les_deux_actions_sont_visibles_pour_un_manager_sur_une_facture_creditable(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionVisible('generateTotalCreditNote')
            ->assertActionVisible('generatePartialCreditNote');
    }

    /**
     * Sécurité — un viewer peut consulter ViewInvoice (lecture ouverte),
     * mais les deux actions doivent y rester invisibles malgré tout.
     */
    public function test_les_deux_actions_restent_invisibles_pour_un_viewer(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->assertActionHidden('generateTotalCreditNote')
            ->assertActionHidden('generatePartialCreditNote');
    }

    public function test_appeler_laction_avoir_total_credite_automatiquement_toutes_les_lignes(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('generateTotalCreditNote', data: [
                'settlement_type' => CreditNote::SETTLEMENT_REFUND,
                'reason' => 'Avoir total via action',
            ]);

        $creditNote = CreditNote::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(CreditNote::SCOPE_TOTAL, $creditNote->scope);
        $this->assertCount(2, $creditNote->lines);
        $this->assertTrue(CreditNote::creditableLinesFor($invoice->fresh())->isEmpty());
    }

    public function test_appeler_laction_avoir_partiel_credite_uniquement_les_lignes_selectionnees(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager);
        $firstLineId = $invoice->lines->first()->id;

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->callAction('generatePartialCreditNote', data: [
                'invoice_line_ids' => [$firstLineId],
                'settlement_type' => CreditNote::SETTLEMENT_FUTURE_INVOICE,
                'reason' => 'Avoir partiel via action',
            ]);

        $creditNote = CreditNote::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(CreditNote::SCOPE_PARTIAL, $creditNote->scope);
        $this->assertCount(1, $creditNote->lines);
        $this->assertCount(1, CreditNote::creditableLinesFor($invoice->fresh()));
    }

    /**
     * Étape T25-B — appel direct de mountAction() (pas le helper de
     * test callAction(), qui pré-vérifie lui-même assertActionVisible()
     * et ne testerait donc jamais le contournement réel) : reproduit un
     * appel Livewire forgé, indépendant de ce que l'interface affiche.
     * ->authorize() doit bloquer réellement l'exécution, pas seulement
     * masquer le bouton.
     */
    public function test_un_viewer_ne_peut_pas_creer_un_avoir_total_par_appel_direct_de_laction(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->call('mountAction', 'generateTotalCreditNote');

        $this->assertSame(0, CreditNote::where('invoice_id', $invoice->id)->count());
    }

    public function test_un_viewer_ne_peut_pas_creer_un_avoir_partiel_par_appel_direct_de_laction(): void
    {
        $manager = User::factory()->create()->assignRole('manager');
        $this->actingAs($manager);
        $invoice = $this->makeShippedInvoiceAsManager($manager);

        $this->actingAs(User::factory()->create()->assignRole('viewer'));

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
            ->call('mountAction', 'generatePartialCreditNote');

        $this->assertSame(0, CreditNote::where('invoice_id', $invoice->id)->count());
    }
}
