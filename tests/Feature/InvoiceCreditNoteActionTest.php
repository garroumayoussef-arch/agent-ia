<?php

namespace Tests\Feature;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
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
}
