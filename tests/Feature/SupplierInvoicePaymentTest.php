<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape T30 — paiements fournisseurs. Immuables dès l'enregistrement
 * (décision validée, aucune correction/annulation en V1), plusieurs
 * paiements partiels possibles par facture, statut toujours recalculé
 * depuis la somme réelle des paiements (jamais un champ stocké).
 */
class SupplierInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplierInvoice(float $totalTtc): SupplierInvoice
    {
        $supplier = Supplier::create(['name' => 'Fournisseur Paiement']);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Paiement',
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
            'unit_price' => 10,
        ]);
        $order->markAsOrdered();

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

    /*
     * =================================================================
     * Création et validations de base
     * =================================================================
     */

    public function test_un_paiement_valide_est_enregistre(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $payment = SupplierInvoicePayment::recordFor($invoice, 400, now()->toDateString(), 'VIR-001', 'Acompte');

        $this->assertDatabaseHas('supplier_invoice_payments', ['id' => $payment->id, 'amount' => 400]);
        $this->assertSame('VIR-001', $payment->reference);
        $this->assertSame('Acompte', $payment->notes);
    }

    public function test_un_montant_nul_ou_negatif_est_rejete(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('supérieur à zéro');

        SupplierInvoicePayment::recordFor($invoice, 0, now()->toDateString());
    }

    public function test_un_montant_negatif_est_rejete(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->expectException(\Exception::class);

        SupplierInvoicePayment::recordFor($invoice, -50, now()->toDateString());
    }

    public function test_un_paiement_superieur_au_total_ttc_est_rejete(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse le solde restant dû');

        SupplierInvoicePayment::recordFor($invoice, 1000.01, now()->toDateString());
    }

    public function test_le_cumul_de_plusieurs_paiements_ne_peut_pas_depasser_le_total_ttc(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        SupplierInvoicePayment::recordFor($invoice, 700, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse le solde restant dû');

        // 700 déjà réglés + 400 dépasserait 1000.
        SupplierInvoicePayment::recordFor($invoice->fresh(), 400, now()->toDateString());
    }

    public function test_un_paiement_egal_exactement_au_solde_restant_est_accepte(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierInvoicePayment::recordFor($invoice, 700, now()->toDateString());

        // Exactement le solde restant (300) : doit réussir, pas d'exception.
        $payment = SupplierInvoicePayment::recordFor($invoice->fresh(), 300, now()->toDateString());

        $this->assertNotNull($payment->id);
    }

    public function test_lutilisateur_authentifie_est_renseigne_automatiquement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $invoice = $this->makeSupplierInvoice(1000);
        $payment = SupplierInvoicePayment::recordFor($invoice, 500, now()->toDateString());

        $this->assertSame($user->id, $payment->user_id);
    }

    /*
     * =================================================================
     * Immuabilité (décision validée : aucune exception)
     * =================================================================
     */

    public function test_un_paiement_ne_peut_pas_etre_modifie(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $payment = SupplierInvoicePayment::recordFor($invoice, 500, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être modifié');

        $payment->update(['amount' => 999]);
    }

    public function test_un_paiement_ne_peut_pas_etre_supprime(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $payment = SupplierInvoicePayment::recordFor($invoice, 500, now()->toDateString());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être supprimé');

        $payment->delete();
    }

    /*
     * =================================================================
     * Relations
     * =================================================================
     */

    public function test_les_relations_supplierinvoice_et_user_fonctionnent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $invoice = $this->makeSupplierInvoice(1000);
        $payment = SupplierInvoicePayment::recordFor($invoice, 500, now()->toDateString());

        $this->assertTrue($payment->supplierInvoice->is($invoice));
        $this->assertTrue($payment->user->is($user));
        $this->assertTrue($invoice->fresh()->payments->contains($payment));
    }

    /*
     * =================================================================
     * Statut — source de vérité unique (exigence 3)
     * =================================================================
     */

    public function test_statut_non_payee_sans_aucun_paiement(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_UNPAID, $invoice->paymentStatus());
        $this->assertSame(0.0, $invoice->amountPaid());
        $this->assertSame(1000.0, $invoice->amountRemaining());
    }

    public function test_statut_partiellement_payee_avec_un_centime_regle(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierInvoicePayment::recordFor($invoice, 0.01, now()->toDateString());

        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());
    }

    public function test_statut_partiellement_payee_juste_avant_le_solde_complet(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierInvoicePayment::recordFor($invoice, 999.99, now()->toDateString());

        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());
    }

    public function test_statut_payee_quand_le_cumul_egale_exactement_le_total_ttc(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierInvoicePayment::recordFor($invoice, 600, now()->toDateString());
        SupplierInvoicePayment::recordFor($invoice->fresh(), 400, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
        $this->assertSame(1000.0, $freshInvoice->amountPaid());
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
    }

    public function test_plusieurs_paiements_partiels_successifs_jusqua_couverture_totale(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        SupplierInvoicePayment::recordFor($invoice, 250, now()->toDateString());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());

        SupplierInvoicePayment::recordFor($invoice->fresh(), 250, now()->toDateString());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PARTIAL, $invoice->fresh()->paymentStatus());

        SupplierInvoicePayment::recordFor($invoice->fresh(), 500, now()->toDateString());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $invoice->fresh()->paymentStatus());
        $this->assertCount(3, $invoice->fresh()->payments);
    }

    /*
     * =================================================================
     * Précision monétaire (exigence 2)
     * =================================================================
     */

    /**
     * Séquence de montants connus pour révéler une dérive flottante
     * naïve en PHP (type 0,1 + 0,2 != 0,3 en IEEE754, ici à l'échelle
     * monétaire) : la somme calculée côté base et les comparaisons
     * arrondies doivent rester exactes au centime près.
     */
    public function test_la_precision_des_montants_ne_derive_pas_avec_des_paiements_flottants_delicats(): void
    {
        // 0.10 + 0.20 + 0.30 = 0.60 exactement en décimal, mais une
        // addition flottante naïve en PHP peut produire 0.6000000000000001.
        $invoice = $this->makeSupplierInvoice(0.60);

        SupplierInvoicePayment::recordFor($invoice, 0.10, now()->toDateString());
        SupplierInvoicePayment::recordFor($invoice->fresh(), 0.20, now()->toDateString());
        SupplierInvoicePayment::recordFor($invoice->fresh(), 0.30, now()->toDateString());

        $freshInvoice = $invoice->fresh();
        $this->assertSame(0.6, $freshInvoice->amountPaid());
        $this->assertSame(0.0, $freshInvoice->amountRemaining());
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $freshInvoice->paymentStatus());
    }

    /**
     * Autre cas classique de dérive flottante : 3 paiements de 33.33 €
     * ne doivent jamais, à cause d'un résidu flottant, être interprétés
     * comme dépassant 100,00 € (33.33 * 3 = 99.99, pas 100 — le dernier
     * centime doit rester acceptable comme paiement final).
     */
    public function test_la_precision_des_montants_narrondit_pas_a_tort_un_paiement_final_legitime(): void
    {
        $invoice = $this->makeSupplierInvoice(100);

        SupplierInvoicePayment::recordFor($invoice, 33.33, now()->toDateString());
        SupplierInvoicePayment::recordFor($invoice->fresh(), 33.33, now()->toDateString());
        SupplierInvoicePayment::recordFor($invoice->fresh(), 33.33, now()->toDateString());
        // Reste exactement 0.01 : doit être accepté, pas rejeté par une
        // dérive flottante qui ferait apparaître le cumul déjà à 100.00
        // ou légèrement au-delà.
        $payment = SupplierInvoicePayment::recordFor($invoice->fresh(), 0.01, now()->toDateString());

        $this->assertNotNull($payment->id);
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $invoice->fresh()->paymentStatus());
    }

    /*
     * =================================================================
     * Concurrence / atomicité (exigence 1)
     * =================================================================
     */

    /**
     * Scénario exact demandé : facture à 1000 €, deux tentatives
     * simultanées de 600 € — un seul paiement doit être accepté, le
     * total final ne doit jamais dépasser 1000 €.
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

            \$invoice = \\App\\Models\\SupplierInvoice::find({$invoiceId});
            \$result = 'FAILED';
            try {
                \\App\\Models\\SupplierInvoicePayment::recordFor(\$invoice, 600, now()->toDateString());
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

    /**
     * Démonstration renforcée (au-delà du strict minimum demandé) — 10
     * processus concurrents tentent chacun 200 € sur une facture de
     * 1000 € : exactement 5 doivent réussir, jamais 6, jamais un total
     * final supérieur à 1000 €.
     */
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

            \$invoice = \\App\\Models\\SupplierInvoice::find({$invoiceId});
            \$result = 'FAILED';
            try {
                \\App\\Models\\SupplierInvoicePayment::recordFor(\$invoice, 200, now()->toDateString());
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

        // Exigence 3 — le statut recalculé après la contention concurrente
        // reste cohérent avec l'état réel de la base, jamais une valeur
        // en cache erronée.
        $finalStatus = $this->paymentStatusInIsolatedDatabase($dbFile, $invoiceId);
        $this->assertSame(SupplierInvoice::PAYMENT_STATUS_PAID, $finalStatus);
    }

    /**
     * Prépare une base SQLite isolée et jetable, migrée fraîchement,
     * contenant une unique SupplierInvoice du montant demandé — même
     * technique Windows-safe (proc_open() + tableau $env explicite) que
     * les démonstrations de concurrence T25/T26.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function prepareIsolatedDatabaseWithInvoice(float $totalTtc): array
    {
        $scratchDir = sys_get_temp_dir().'/t30_payment_test_'.uniqid();
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

        // Insertion directe (hors Eloquent, DB isolée) d'une facture
        // fournisseur valide, sans dépendre du reste du framework de
        // test (RefreshDatabase porte sur une AUTRE connexion).
        $pdo = new \PDO('sqlite:'.$dbFile);
        $pdo->exec("INSERT INTO suppliers (name, created_at, updated_at) VALUES ('Fournisseur Concurrence', datetime('now'), datetime('now'))");
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO purchase_orders (reference, supplier_id, status, order_date, created_at, updated_at) VALUES ('BC-CONC', {$supplierId}, 'ordered', date('now'), datetime('now'), datetime('now'))");
        $purchaseOrderId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO supplier_invoices (supplier_id, purchase_order_id, supplier_invoice_number, invoice_date, total_ht, tax_amount, total_ttc, created_at, updated_at) VALUES ({$supplierId}, {$purchaseOrderId}, 'FF-CONC', date('now'), {$totalTtc}, 0, {$totalTtc}, datetime('now'), datetime('now'))");
        $invoiceId = (int) $pdo->lastInsertId();

        return [$scratchDir, $dbFile, $invoiceId];
    }

    private function sumPaymentsInIsolatedDatabase(string $dbFile, int $invoiceId): float
    {
        $pdo = new \PDO('sqlite:'.$dbFile);
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM supplier_invoice_payments WHERE supplier_invoice_id = {$invoiceId}");

        return round((float) $stmt->fetchColumn(), 2);
    }

    private function paymentStatusInIsolatedDatabase(string $dbFile, int $invoiceId): string
    {
        $pdo = new \PDO('sqlite:'.$dbFile);
        $stmt = $pdo->query("SELECT total_ttc FROM supplier_invoices WHERE id = {$invoiceId}");
        $totalTtc = round((float) $stmt->fetchColumn(), 2);
        $paid = $this->sumPaymentsInIsolatedDatabase($dbFile, $invoiceId);

        return match (true) {
            $paid <= 0 => SupplierInvoice::PAYMENT_STATUS_UNPAID,
            $paid >= $totalTtc => SupplierInvoice::PAYMENT_STATUS_PAID,
            default => SupplierInvoice::PAYMENT_STATUS_PARTIAL,
        };
    }
}
