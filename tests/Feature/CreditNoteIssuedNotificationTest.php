<?php

namespace Tests\Feature;

use App\Mail\CreditNoteIssuedMail;
use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Chantier "Notifications & communication" V1 (D1/D2/D4/D5/D8, validés)
 * — email client à l'émission d'un avoir.
 */
class CreditNoteIssuedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt', 'code' => 'defaut', 'is_default' => true]);
        TaxRate::create(['label' => 'TVA 20%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 20, 'is_default_sale' => true, 'is_active' => true]);

        CompanySettings::current()->update([
            'legal_name' => 'Magarrou', 'legal_form' => 'SASU', 'address' => '1 rue du Sport',
            'postal_code' => '75000', 'city' => 'Paris', 'country' => 'France', 'siren' => '111222333',
            'vat_regime' => CompanySettings::VAT_REGIME_STANDARD, 'vat_number' => 'FR11111222333',
        ]);
    }

    private function makeCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Client Notif', 'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse', 'city' => 'Lyon', 'country' => 'France',
            'email' => 'client@example.test',
        ], $attributes));
    }

    private function makeInvoice(Customer $customer): Invoice
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(), 'nom' => 'Maillot', 'categorie' => 'Maillots',
            'type' => 'Player Version', 'taille' => 'M', 'stock' => 100, 'prix_achat' => 10, 'prix_vente' => 20,
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity_ordered' => 1, 'unit_price' => 20]);
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 1]);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    public function test_un_email_est_mis_en_file_a_lemission_dun_avoir(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer);

        Mail::fake();
        $lineIds = CreditNote::creditableLinesFor($invoice)->pluck('id')->all();
        $creditNote = CreditNote::generateFromInvoice($invoice, $lineIds, 'Motif', CreditNote::SETTLEMENT_REFUND);

        Mail::assertQueued(CreditNoteIssuedMail::class, fn (CreditNoteIssuedMail $mail): bool => $mail->creditNote->is($creditNote));
    }

    public function test_un_journal_est_cree_avec_le_statut_en_file(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer);

        Mail::fake();
        $lineIds = CreditNote::creditableLinesFor($invoice)->pluck('id')->all();
        $creditNote = CreditNote::generateFromInvoice($invoice, $lineIds, 'Motif', CreditNote::SETTLEMENT_REFUND);

        $log = NotificationLog::where('notifiable_type', CreditNote::class)
            ->where('notifiable_id', $creditNote->id)
            ->where('event_type', 'credit_note_issued')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_QUEUED, $log->status);
    }

    public function test_aucun_email_nest_mis_en_file_si_le_client_na_pas_dadresse(): void
    {
        $customer = $this->makeCustomer(['email' => null]);
        $invoice = $this->makeInvoice($customer);

        Mail::fake();
        $lineIds = CreditNote::creditableLinesFor($invoice)->pluck('id')->all();
        CreditNote::generateFromInvoice($invoice, $lineIds, 'Motif', CreditNote::SETTLEMENT_REFUND);

        Mail::assertNothingQueued();
    }

    public function test_le_mailable_contient_le_pdf_en_piece_jointe(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer);
        $lineIds = CreditNote::creditableLinesFor($invoice)->pluck('id')->all();
        $creditNote = CreditNote::generateFromInvoice($invoice, $lineIds, 'Motif', CreditNote::SETTLEMENT_REFUND);
        $log = NotificationLog::where('notifiable_type', CreditNote::class)->where('notifiable_id', $creditNote->id)->first();

        $mailable = new CreditNoteIssuedMail($creditNote->fresh(), $log->id);

        $this->assertCount(1, $mailable->attachments());
    }

    /**
     * D11 (validé, chantier VTC) — un avoir sur une facture VTC déclenche
     * la même notification, sans aucune adaptation : preuve directe de
     * la réutilisation stricte (D1 de ce chantier).
     */
    public function test_fonctionne_egalement_sur_un_avoir_de_facture_vtc(): void
    {
        $customer = $this->makeCustomer();
        $rate = TaxRate::create(['label' => 'VTC 10%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true]);
        \App\Models\FiscalSetting::updateOrCreate(['activity' => \App\Models\FiscalSetting::ACTIVITY_VTC], ['tax_rate_id' => $rate->id]);
        $ride = \App\Models\VtcRide::create([
            'reference' => 'VTC-'.uniqid(),
            'customer_id' => $customer->id,
            'driver_id' => \App\Models\Driver::create(['name' => 'Chauffeur', 'is_active' => true])->id,
            'vehicle_id' => \App\Models\Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ', 'is_active' => true])->id,
            'price_ht' => 100,
        ]);
        $ride->markAsConfirmed();
        $invoice = Invoice::generateFromVtcRide($ride->fresh());

        Mail::fake();
        $lineIds = CreditNote::creditableLinesFor($invoice)->pluck('id')->all();
        $creditNote = CreditNote::generateFromInvoice($invoice, $lineIds, 'Course annulée', CreditNote::SETTLEMENT_REFUND);

        Mail::assertQueued(CreditNoteIssuedMail::class, fn (CreditNoteIssuedMail $mail): bool => $mail->creditNote->is($creditNote));
    }
}
