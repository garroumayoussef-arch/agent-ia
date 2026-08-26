<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T31 — paiements clients, symétrique exact de
 * SupplierInvoicePaymentTest (T30). Immuables dès l'enregistrement
 * (décision validée, aucune correction/annulation en V1), plusieurs
 * paiements partiels possibles par facture, statut toujours recalculé
 * depuis la somme réelle des paiements (jamais un champ stocké).
 */
class InvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Warehouse::create(['name' => 'Entrepôt par défaut', 'code' => 'defaut', 'is_default' => true]);

        // Taux de vente 0% : total_ttc = total_ht, calcul de référence
        // simple et non ambigu, même convention que
        // PurchasingOverviewTest/SupplierInvoicePaymentTest côté achats.
        TaxRate::create([
            'label' => 'Vente 0%',
            'type' => TaxRate::TYPE_PERCENTAGE,
            'rate' => 0,
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

    private function makeInvoice(float $totalTtc): Invoice
    {
        $customer = Customer::create([
            'name' => 'Client Paiement',
            'customer_type' => Customer::TYPE_INDIVIDUAL,
            'address' => 'Adresse',
            'city' => 'Lyon',
            'country' => 'France',
        ]);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Paiement',
            'categorie' => 'Maillots',
            'type' => 'Player Version',
            'taille' => 'M',
            'stock' => 100,
            'prix_achat' => 10,
            'prix_vente' => $totalTtc,
        ]);
        $order = SalesOrder::create(['reference' => 'CMD-'.uniqid(), 'customer_id' => $customer->id]);
        $item = SalesOrderItem::create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity_ordered' => 1,
            'unit_price' => $totalTtc,
        ]);

        $order->markAsConfirmed();
        $order->fresh()->ship([$item->id => 1]);

        return Invoice::generateFromSalesOrder($order->fresh());
    }

    /*
     * =================================================================
     * Création et validations de base
     * =================================================================
     */

    public function test_un_paiement_valide_est_enregistre(): void
    {
        $invoice = $this->makeInvoice(1000);

        $payment = InvoicePayment::recordFor($invoice, 400, now()->toDateString(), 'VIR-001', 'Acompte');

        $this->assertDatabaseHas('invoice_payments', ['id' => $payment->id, 'amount' => 400]);
        $this->assertSame('VIR-001', $payment->reference);
        $this->assertSame('Acompte', $payment->notes);
    }

    public function test_un_montant_nul_est_rejete(): void
    {
        $invoice = $this->makeInvoice(1000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('supérieur à zéro');

        InvoicePayment::recordFor($invoice, 0, now()->toDateString());
    }

    public function test_un_montant_negatif_est_rejete(): void
    {
        $invoice = $this->makeInvoice(1000);

        $this->expectException(\Exception::class);

        InvoicePayment::recordFor($invoice, -50, now()->toDateString());
    }

    public function test_un_paiement_superieur_au_total_ttc_est_rejete(): void
    {
        $invoice = $this->makeInvoice(1000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse le solde restant dû');

        InvoicePayment::recordFor($invoice, 1000.01, now()->toDateString());
    }

    public function test_le_cumul_de_plusieurs_paiements_ne_peut_pas_depasser_le_total_ttc(): void
    {
        $invoice = $this->makeInvoice(1000);

        InvoicePayment::recordFor($invoice, 700, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse le solde restant dû');

        InvoicePayment::recordFor($invoice->fresh(), 400, now()->toDateString());
    }

    public function test_un_paiement_egal_exactement_au_solde_restant_est_accepte(): void
    {
        $invoice = $this->makeInvoice(1000);
        InvoicePayment::recordFor($invoice, 700, now()->toDateString());

        $payment = InvoicePayment::recordFor($invoice->fresh(), 300, now()->toDateString());

        $this->assertNotNull($payment->id);
    }

    public function test_lutilisateur_authentifie_est_renseigne_automatiquement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $invoice = $this->makeInvoice(1000);
        $payment = InvoicePayment::recordFor($invoice, 500, now()->toDateString());

        $this->assertSame($user->id, $payment->user_id);
    }

    /*
     * =================================================================
     * Immuabilité (décision validée : aucune exception)
     * =================================================================
     */

    public function test_un_paiement_ne_peut_pas_etre_modifie(): void
    {
        $invoice = $this->makeInvoice(1000);
        $payment = InvoicePayment::recordFor($invoice, 500, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être modifié');

        $payment->update(['amount' => 999]);
    }

    public function test_un_paiement_ne_peut_pas_etre_supprime(): void
    {
        $invoice = $this->makeInvoice(1000);
        $payment = InvoicePayment::recordFor($invoice, 500, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être supprimé');

        $payment->delete();
    }

    /*
     * =================================================================
     * Relations
     * =================================================================
     */

    public function test_les_relations_invoice_et_user_fonctionnent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $invoice = $this->makeInvoice(1000);
        $payment = InvoicePayment::recordFor($invoice, 500, now()->toDateString());

        $this->assertTrue($payment->invoice->is($invoice));
        $this->assertTrue($payment->user->is($user));
        $this->assertTrue($invoice->fresh()->payments->contains($payment));
    }

    /*
     * =================================================================
     * Statut — source de vérité unique
     * =================================================================
     */

    public function test_statut_non_payee_sans_aucun_paiement(): void
    {
        $invoice = $this->makeInvoice(1000);

        $this->assertSame(Invoice::PAYMENT_STATUS_UNPAID, $invoice->paymentStatus());
        $this->assertSame(0.0, $invoice->amountPaid());
        $this->assertSame(1000.0, $invoice->amountRemaining());
    }

    public function test_statut_partiellement_payee_avec_un_centime_regle(): void
    {
        $invoice = $this->makeInvoice(1000);
        InvoicePayment::recordFor($invoice, 0.01, now()->toDateString());

        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());
    }

    public function test_statut_partiellement_payee_juste_avant_le_solde_complet(): void
    {
        $invoice = $this->makeInvoice(1000);
        InvoicePayment::recordFor($invoice, 999.99, now()->toDateString());

        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());
    }

    public function test_statut_payee_quand_le_cumul_egale_exactement_le_total_ttc(): void
    {
        $invoice = $this->makeInvoice(1000);
        InvoicePayment::recordFor($invoice, 600, now()->toDateString());
        InvoicePayment::recordFor($invoice->fresh(), 400, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
        $this->assertSame(1000.0, $freshInvoice->amountPaid());
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
    }

    public function test_plusieurs_paiements_partiels_successifs_jusqua_couverture_totale(): void
    {
        $invoice = $this->makeInvoice(1000);

        InvoicePayment::recordFor($invoice, 250, now()->toDateString());
        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());

        InvoicePayment::recordFor($invoice->fresh(), 250, now()->toDateString());
        $this->assertSame(Invoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());

        InvoicePayment::recordFor($invoice->fresh(), 500, now()->toDateString());
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $invoice->fresh()->paymentStatus());
        $this->assertCount(3, $invoice->fresh()->payments);
    }

    /*
     * =================================================================
     * Précision monétaire
     * =================================================================
     */

    public function test_la_precision_des_montants_ne_derive_pas_avec_des_paiements_flottants_delicats(): void
    {
        $invoice = $this->makeInvoice(0.60);

        InvoicePayment::recordFor($invoice, 0.10, now()->toDateString());
        InvoicePayment::recordFor($invoice->fresh(), 0.20, now()->toDateString());
        InvoicePayment::recordFor($invoice->fresh(), 0.30, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.6, $freshInvoice->amountPaid());
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    public function test_la_precision_des_montants_narrondit_pas_a_tort_un_paiement_final_legitime(): void
    {
        $invoice = $this->makeInvoice(100);

        InvoicePayment::recordFor($invoice, 33.33, now()->toDateString());
        InvoicePayment::recordFor($invoice->fresh(), 33.33, now()->toDateString());
        InvoicePayment::recordFor($invoice->fresh(), 33.33, now()->toDateString());
        $payment = InvoicePayment::recordFor($invoice->fresh(), 0.01, now()->toDateString());

        $this->assertNotNull($payment->id);
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $invoice->fresh()->paymentStatus());
    }

    /*
     * =================================================================
     * Concurrence / atomicité
     * =================================================================
     */

    public function test_deux_paiements_concurrents_de_600_sur_une_facture_de_1000_un_seul_est_accepte(): void
    {
        [$scratchDir, $dbFile, $invoiceId] = $this->prepareIsolatedDatabaseWithInvoice(1000);

        $basePath = base_path();
        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();
        $resultsFile = $scratchDir.'/results.txt';
        $probeFile = $scratchDir.'/probe.php';

        file_put_contents($probeFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \$invoice = \\App\\Models\\Invoice::find({$invoiceId});
            \$result = 'FAILED';
            try {
                \\App\\Models\\InvoicePayment::recordFor(\$invoice, 600, now()->toDateString());
                \$result = 'ACCEPTED';
            } catch (\\Throwable \$e) {
                \$result = 'REJECTED';
            }

            \$fp = fopen(\$argv[1], 'a');
            flock(\$fp, LOCK_EX);
            fwrite(\$fp, \$result."\\n");
            flock(\$fp, LOCK_UN);
            fclose(\$fp);
            PHP);

        $handles = [];
        $allPipes = [];
        for ($i = 0; $i < 2; $i++) {
            $handles[] = proc_open(
                ['php', $probeFile, $resultsFile],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $envOverrides,
            );
            $allPipes[$i] = $pipes;
        }
        foreach ($handles as $i => $handle) {
            stream_get_contents($allPipes[$i][1]);
            stream_get_contents($allPipes[$i][2]);
            proc_close($handle);
        }

        $results = array_values(array_filter(explode("\n", file_get_contents($resultsFile))));
        $accepted = count(array_filter($results, fn ($r) => $r === 'ACCEPTED'));
        $rejected = count(array_filter($results, fn ($r) => $r === 'REJECTED'));

        $this->assertSame(1, $accepted, 'Exactement un des deux paiements concurrents doit être accepté.');
        $this->assertSame(1, $rejected, "L'autre doit être rejeté (dépassement du solde).");

        $totalPaidInIsolatedDb = $this->sumPaymentsInIsolatedDatabase($dbFile, $invoiceId);
        $this->assertLessThanOrEqual(1000.0, $totalPaidInIsolatedDb, 'Le total réellement persisté ne doit jamais dépasser 1000 €.');
        $this->assertSame(600.0, $totalPaidInIsolatedDb, 'Un seul paiement de 600 € doit avoir été persisté.');
    }

    public function test_dix_paiements_concurrents_de_200_sur_une_facture_de_1000_exactement_cinq_sont_acceptes(): void
    {
        [$scratchDir, $dbFile, $invoiceId] = $this->prepareIsolatedDatabaseWithInvoice(1000);

        $basePath = base_path();
        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();
        $resultsFile = $scratchDir.'/results.txt';
        $probeFile = $scratchDir.'/probe.php';

        file_put_contents($probeFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \$invoice = \\App\\Models\\Invoice::find({$invoiceId});
            \$result = 'FAILED';
            try {
                \\App\\Models\\InvoicePayment::recordFor(\$invoice, 200, now()->toDateString());
                \$result = 'ACCEPTED';
            } catch (\\Throwable \$e) {
                \$result = 'REJECTED';
            }

            \$fp = fopen(\$argv[1], 'a');
            flock(\$fp, LOCK_EX);
            fwrite(\$fp, \$result."\\n");
            flock(\$fp, LOCK_UN);
            fclose(\$fp);
            PHP);

        $processCount = 10;
        $handles = [];
        $allPipes = [];
        for ($i = 0; $i < $processCount; $i++) {
            $handles[] = proc_open(
                ['php', $probeFile, $resultsFile],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $envOverrides,
            );
            $allPipes[$i] = $pipes;
        }
        foreach ($handles as $i => $handle) {
            stream_get_contents($allPipes[$i][1]);
            stream_get_contents($allPipes[$i][2]);
            proc_close($handle);
        }

        $results = array_values(array_filter(explode("\n", file_get_contents($resultsFile))));
        $accepted = count(array_filter($results, fn ($r) => $r === 'ACCEPTED'));

        $this->assertSame(10, count($results), 'Les 10 processus doivent avoir répondu.');
        $this->assertSame(5, $accepted, 'Exactement 5 paiements de 200 € doivent être acceptés (5 x 200 = 1000).');

        $totalPaidInIsolatedDb = $this->sumPaymentsInIsolatedDatabase($dbFile, $invoiceId);
        $this->assertSame(1000.0, $totalPaidInIsolatedDb, 'Le total final doit être strictement égal à 1000 €, jamais davantage.');

        $finalStatus = $this->paymentStatusInIsolatedDatabase($dbFile, $invoiceId);
        $this->assertSame(Invoice::PAYMENT_STATUS_PAID, $finalStatus);
    }

    /**
     * Prépare une base SQLite isolée et jetable, migrée fraîchement,
     * contenant une unique Invoice du montant demandé — insertion SQL
     * brute pour satisfaire toutes les colonnes NOT NULL du schéma
     * invoices (snapshot vendeur/acheteur), plus un sales_orders minimal
     * pour satisfaire la contrainte de clé étrangère.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function prepareIsolatedDatabaseWithInvoice(float $totalTtc): array
    {
        $scratchDir = sys_get_temp_dir().'/t31_payment_test_'.uniqid();
        mkdir($scratchDir);
        $dbFile = $scratchDir.'/concurrency.sqlite';
        touch($dbFile);

        $basePath = base_path();
        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();

        $migrateProcess = proc_open(
            ['php', 'artisan', 'migrate', '--database=sqlite', '--force'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $migratePipes,
            $basePath,
            $envOverrides,
        );
        $migrateOutput = stream_get_contents($migratePipes[1]).stream_get_contents($migratePipes[2]);
        $migrateStatus = proc_close($migrateProcess);
        $this->assertSame(0, $migrateStatus, 'La migration de la base isolée a échoué : '.$migrateOutput);

        $pdo = new \PDO('sqlite:'.$dbFile);
        $pdo->exec("INSERT INTO sales_orders (reference, status, created_at, updated_at) VALUES ('CMD-CONC', 'shipped', datetime('now'), datetime('now'))");
        $salesOrderId = (int) $pdo->lastInsertId();

        $pdo->exec(<<<SQL
            INSERT INTO invoices (
                sales_order_id, number, issued_at, sale_completed_at, operation_category,
                transaction_type, sales_order_reference, customer_type, customer_name,
                seller_legal_name, seller_address, seller_postal_code, seller_city, seller_country,
                seller_siren, vat_regime_snapshot, recovery_indemnity_amount_snapshot,
                total_ht, discount_amount, tax_amount, total_ttc, status, created_at, updated_at
            ) VALUES (
                {$salesOrderId}, 'FA-CONC', date('now'), date('now'), 'vente',
                'b2c_domestic', 'CMD-CONC', 'individual', 'Client Concurrence',
                'Magarrou', '1 rue du Sport', '75000', 'Paris', 'France',
                '111222333', 'standard', 40,
                {$totalTtc}, 0, 0, {$totalTtc}, 'issued', datetime('now'), datetime('now')
            )
            SQL);
        $invoiceId = (int) $pdo->lastInsertId();

        return [$scratchDir, $dbFile, $invoiceId];
    }

    private function sumPaymentsInIsolatedDatabase(string $dbFile, int $invoiceId): float
    {
        $pdo = new \PDO('sqlite:'.$dbFile);
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = {$invoiceId}");

        return round((float) $stmt->fetchColumn(), 2);
    }

    private function paymentStatusInIsolatedDatabase(string $dbFile, int $invoiceId): string
    {
        $pdo = new \PDO('sqlite:'.$dbFile);
        $stmt = $pdo->query("SELECT total_ttc FROM invoices WHERE id = {$invoiceId}");
        $totalTtc = round((float) $stmt->fetchColumn(), 2);
        $paid = $this->sumPaymentsInIsolatedDatabase($dbFile, $invoiceId);

        return match (true) {
            $paid <= 0 => Invoice::PAYMENT_STATUS_UNPAID,
            $paid >= $totalTtc => Invoice::PAYMENT_STATUS_PAID,
            default => Invoice::PAYMENT_STATUS_PARTIAL,
        };
    }
}
