<?php

namespace Tests\Feature;

use App\Mail\CustomerReturnIssuedMail;
use App\Models\CompanySettings;
use App\Models\CreditNote;
use App\Models\CreditNoteLineReturn;
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
 * — email client à l'enregistrement d'un bon de retour physique.
 */
class CustomerReturnNotificationTest extends TestCase
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

    private function makeCreditableLine(Customer $customer): \App\Models\CreditNoteLine
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(), 'nom' => 'Maillot', 'categorie' => 'Maillots',
            'type' => 'Player Version', 'taille' => 'M', 'stock' => 100, 'prix_achat' => 10, 'prix_vente' => 20,
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity_ordered' => 2, 'unit_price' => 20]);
        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 2]);
        $invoice = Invoice::generateFromSalesOrder($order->fresh());
        $lineIds = CreditNote::creditableLinesFor($invoice)->pluck('id')->all();
        $creditNote = CreditNote::generateFromInvoice($invoice, $lineIds, 'Motif', CreditNote::SETTLEMENT_REFUND);

        return $creditNote->lines()->first();
    }

    public function test_un_email_est_mis_en_file_a_lenregistrement_dun_retour(): void
    {
        $customer = $this->makeCustomer();
        $line = $this->makeCreditableLine($customer);

        Mail::fake();
        $return = CreditNoteLineReturn::recordFor($line, 1, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        Mail::assertQueued(CustomerReturnIssuedMail::class, fn (CustomerReturnIssuedMail $mail): bool => $mail->return->is($return));
    }

    public function test_un_journal_est_cree_avec_le_statut_en_file(): void
    {
        $customer = $this->makeCustomer();
        $line = $this->makeCreditableLine($customer);

        Mail::fake();
        $return = CreditNoteLineReturn::recordFor($line, 1, CreditNoteLineReturn::CONDITION_DEFECTIVE, now()->toDateString());

        $log = NotificationLog::where('notifiable_type', CreditNoteLineReturn::class)
            ->where('notifiable_id', $return->id)
            ->where('event_type', 'customer_return_issued')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_QUEUED, $log->status);
    }

    public function test_aucun_email_nest_mis_en_file_si_le_client_na_pas_dadresse(): void
    {
        $customer = $this->makeCustomer(['email' => null]);
        $line = $this->makeCreditableLine($customer);

        Mail::fake();
        CreditNoteLineReturn::recordFor($line, 1, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());

        Mail::assertNothingQueued();
    }

    public function test_le_mailable_contient_le_pdf_en_piece_jointe(): void
    {
        $customer = $this->makeCustomer();
        $line = $this->makeCreditableLine($customer);
        $return = CreditNoteLineReturn::recordFor($line, 1, CreditNoteLineReturn::CONDITION_SELLABLE, now()->toDateString());
        $log = NotificationLog::where('notifiable_type', CreditNoteLineReturn::class)->where('notifiable_id', $return->id)->first();

        $mailable = new CustomerReturnIssuedMail($return->fresh(), $log->id);

        $this->assertCount(1, $mailable->attachments());
    }
}
