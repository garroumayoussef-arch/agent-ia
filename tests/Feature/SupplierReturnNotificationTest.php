<?php

namespace Tests\Feature;

use App\Mail\SupplierReturnIssuedMail;
use App\Models\CompanySettings;
use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderItemReturn;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Chantier "Notifications & communication" V1 (D1/D2/D4/D5/D8, validés)
 * — email fournisseur à l'enregistrement d'un retour physique de
 * marchandise.
 */
class SupplierReturnNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Warehouse::create(['name' => 'Entrepôt', 'code' => 'defaut', 'is_default' => true]);

        CompanySettings::current()->update([
            'legal_name' => 'Magarrou', 'legal_form' => 'SASU', 'address' => '1 rue du Sport',
            'postal_code' => '75000', 'city' => 'Paris', 'country' => 'France', 'siren' => '111222333',
            'vat_regime' => CompanySettings::VAT_REGIME_STANDARD, 'vat_number' => 'FR11111222333',
        ]);
    }

    private function makeSupplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'name' => 'Fournisseur Notif',
            'email' => 'fournisseur@example.test',
        ], $attributes));
    }

    private function makeReceivedItem(Supplier $supplier): PurchaseOrderItem
    {
        $product = Product::create([
            'reference' => 'REF-'.uniqid(), 'nom' => 'Maillot', 'categorie' => 'Maillots',
            'type' => 'Player Version', 'taille' => 'M', 'stock' => 100, 'prix_achat' => 10, 'prix_vente' => 20,
        ]);
        $order = PurchaseOrder::create(['reference' => 'BC-'.uniqid(), 'supplier_id' => $supplier->id]);
        $item = PurchaseOrderItem::create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'quantity_ordered' => 5, 'unit_price' => 10]);
        $order->markAsOrdered();
        $order->fresh()->receive([$item->id => 5], Warehouse::where('is_default', true)->value('id'));

        return $item->fresh();
    }

    public function test_un_email_est_mis_en_file_a_lenregistrement_dun_retour_fournisseur(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeReceivedItem($supplier);

        Mail::fake();
        $return = PurchaseOrderItemReturn::recordFor($item, 1, now()->toDateString(), Warehouse::where('is_default', true)->value('id'));

        Mail::assertQueued(SupplierReturnIssuedMail::class, fn (SupplierReturnIssuedMail $mail): bool => $mail->return->is($return));
    }

    public function test_un_journal_est_cree_avec_le_statut_en_file(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeReceivedItem($supplier);

        Mail::fake();
        $return = PurchaseOrderItemReturn::recordFor($item, 1, now()->toDateString(), Warehouse::where('is_default', true)->value('id'));

        $log = NotificationLog::where('notifiable_type', PurchaseOrderItemReturn::class)
            ->where('notifiable_id', $return->id)
            ->where('event_type', 'supplier_return_issued')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_QUEUED, $log->status);
    }

    public function test_aucun_email_nest_mis_en_file_si_le_fournisseur_na_pas_dadresse(): void
    {
        $supplier = $this->makeSupplier(['email' => null]);
        $item = $this->makeReceivedItem($supplier);

        Mail::fake();
        PurchaseOrderItemReturn::recordFor($item, 1, now()->toDateString(), Warehouse::where('is_default', true)->value('id'));

        Mail::assertNothingQueued();
    }

    public function test_le_mailable_contient_le_pdf_en_piece_jointe(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeReceivedItem($supplier);
        $return = PurchaseOrderItemReturn::recordFor($item, 1, now()->toDateString(), Warehouse::where('is_default', true)->value('id'));
        $log = NotificationLog::where('notifiable_type', PurchaseOrderItemReturn::class)->where('notifiable_id', $return->id)->first();

        $mailable = new SupplierReturnIssuedMail($return->fresh(), $log->id);

        $this->assertCount(1, $mailable->attachments());
    }
}
