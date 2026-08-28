<?php

namespace Tests\Feature;

use App\Mail\InvoiceIssuedMail;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FiscalSetting;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\Vehicle;
use App\Models\VtcRide;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Chantier "Notifications & communication" V1 (D1/D2/D4/D5/D8, validés)
 * — email client à l'émission d'une facture, vente ET VTC (agnostique
 * de l'origine — même dispatch réutilisé par les deux méthodes de
 * génération, cf. Invoice::dispatchInvoiceIssuedNotification()).
 */
class InvoiceIssuedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

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
            'vtc_invoice_number_prefix' => 'FV',
        ]);
    }

    private function makeCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Client Notif',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
            'email' => 'client@example.test',
        ], $attributes));
    }

    private function makeShippedSalesInvoice(Customer $customer): Invoice
    {
        Warehouse::create(['name' => 'Entrepôt', 'code' => 'defaut', 'is_default' => true]);
        TaxRate::create(['label' => 'TVA 20%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 20, 'is_default_sale' => true, 'is_active' => true]);
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

    private function makeVtcInvoice(Customer $customer): Invoice
    {
        $rate = TaxRate::create(['label' => 'VTC 10%', 'type' => TaxRate::TYPE_PERCENTAGE, 'rate' => 10, 'is_active' => true]);
        FiscalSetting::updateOrCreate(['activity' => FiscalSetting::ACTIVITY_VTC], ['tax_rate_id' => $rate->id]);
        $ride = VtcRide::create([
            'reference' => 'VTC-'.uniqid(),
            'customer_id' => $customer->id,
            'driver_id' => Driver::create(['name' => 'Chauffeur', 'is_active' => true])->id,
            'vehicle_id' => Vehicle::create(['plate_number' => 'AA-'.uniqid().'-ZZ', 'is_active' => true])->id,
            'price_ht' => 100,
        ]);
        $ride->markAsConfirmed();

        return Invoice::generateFromVtcRide($ride->fresh());
    }

    public function test_un_email_est_mis_en_file_a_lemission_dune_facture_de_vente(): void
    {
        Mail::fake();
        $customer = $this->makeCustomer();

        $invoice = $this->makeShippedSalesInvoice($customer);

        Mail::assertQueued(InvoiceIssuedMail::class, fn (InvoiceIssuedMail $mail): bool => $mail->invoice->is($invoice));
    }

    public function test_un_email_est_mis_en_file_a_lemission_dune_facture_vtc(): void
    {
        Mail::fake();
        $customer = $this->makeCustomer();

        $invoice = $this->makeVtcInvoice($customer);

        Mail::assertQueued(InvoiceIssuedMail::class, fn (InvoiceIssuedMail $mail): bool => $mail->invoice->is($invoice));
    }

    public function test_un_journal_est_cree_avec_le_statut_en_file(): void
    {
        Mail::fake();
        $customer = $this->makeCustomer();

        $invoice = $this->makeShippedSalesInvoice($customer);

        $log = NotificationLog::where('notifiable_type', Invoice::class)
            ->where('notifiable_id', $invoice->id)
            ->where('event_type', 'invoice_issued')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_QUEUED, $log->status);
        $this->assertSame('client@example.test', $log->recipient_address);
    }

    /**
     * D2 (validé) — Customer.email nullable : aucun envoi ne doit être
     * tenté, le journal enregistre l'échec (D9), jamais une exception
     * qui remonterait bloquer la génération de la facture elle-même.
     */
    public function test_aucun_email_nest_mis_en_file_si_le_client_na_pas_dadresse(): void
    {
        Mail::fake();
        $customer = $this->makeCustomer(['email' => null]);

        $invoice = $this->makeShippedSalesInvoice($customer);

        Mail::assertNothingQueued();

        $log = NotificationLog::where('notifiable_type', Invoice::class)->where('notifiable_id', $invoice->id)->first();
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
    }

    /**
     * D4 (validé) — le PDF joint réutilise strictement la même vue que
     * InvoicePdfController (T23) : construction directe du Mailable
     * (sans Mail::fake()) pour vérifier réellement la pièce jointe.
     */
    public function test_le_mailable_contient_le_pdf_en_piece_jointe(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeShippedSalesInvoice($customer);
        $log = NotificationLog::where('notifiable_type', Invoice::class)->where('notifiable_id', $invoice->id)->first();

        $mailable = new InvoiceIssuedMail($invoice->fresh(), $log->id);

        $attachments = $mailable->attachments();
        $this->assertCount(1, $attachments);
    }
}
