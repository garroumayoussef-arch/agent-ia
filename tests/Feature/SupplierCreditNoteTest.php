<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier "avoir fournisseur" — avoirs reçus des fournisseurs.
 * Immuables dès l'enregistrement (décision validée, aucune correction/
 * annulation en V1), plusieurs avoirs partiels possibles par facture,
 * cumul jamais supérieur au total TTC de la facture (jamais un champ
 * stocké, toujours recalculé depuis la somme réelle des avoirs).
 */
class SupplierCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private function makeSupplierInvoice(float $totalTtc): SupplierInvoice
    {
        $supplier = Supplier::create(['name' => 'Fournisseur Avoir']);
        $product = Product::create([
            'reference' => 'REF-'.uniqid(),
            'nom' => 'Maillot Avoir',
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

    public function test_un_avoir_valide_est_enregistre(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $creditNote = SupplierCreditNote::recordFor(
            $invoice,
            'AV-FOURN-001',
            now()->toDateString(),
            400,
            0,
            400,
            'Marchandise défectueuse',
            'Retour partiel',
        );

        $this->assertDatabaseHas('supplier_credit_notes', ['id' => $creditNote->id, 'total_ttc' => 400]);
        $this->assertSame('AV-FOURN-001', $creditNote->supplier_credit_note_number);
        $this->assertSame('Marchandise défectueuse', $creditNote->reason);
        $this->assertSame('Retour partiel', $creditNote->notes);
    }

    public function test_un_avoir_sans_numero_est_rejete(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('obligatoire');

        SupplierCreditNote::recordFor($invoice, '', now()->toDateString(), 400, 0, 400);
    }

    public function test_un_avoir_sans_motif_ni_notes_est_accepte(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $creditNote = SupplierCreditNote::recordFor($invoice, 'AV-FOURN-002', now()->toDateString(), 400, 0, 400);

        $this->assertNull($creditNote->reason);
        $this->assertNull($creditNote->notes);
    }

    public function test_un_montant_nul_ou_negatif_est_rejete(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('supérieur à zéro');

        SupplierCreditNote::recordFor($invoice, 'AV-FOURN-003', now()->toDateString(), 0, 0, 0);
    }

    public function test_un_montant_negatif_est_rejete(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->expectException(\Exception::class);

        SupplierCreditNote::recordFor($invoice, 'AV-FOURN-004', now()->toDateString(), -50, 0, -50);
    }

    public function test_un_avoir_superieur_au_total_ttc_est_rejete(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse le total TTC');

        SupplierCreditNote::recordFor($invoice, 'AV-FOURN-005', now()->toDateString(), 1000.01, 0, 1000.01);
    }

    public function test_le_cumul_de_plusieurs_avoirs_ne_peut_pas_depasser_le_total_ttc(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        SupplierCreditNote::recordFor($invoice, 'AV-FOURN-006', now()->toDateString(), 700, 0, 700);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dépasse le total TTC');

        // 700 déjà crédités + 400 dépasserait 1000.
        SupplierCreditNote::recordFor($invoice->fresh(), 'AV-FOURN-007', now()->toDateString(), 400, 0, 400);
    }

    public function test_un_avoir_egal_exactement_au_solde_restant_est_accepte(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        SupplierCreditNote::recordFor($invoice, 'AV-FOURN-008', now()->toDateString(), 700, 0, 700);

        // Exactement le solde restant (300) : doit réussir, pas d'exception.
        $creditNote = SupplierCreditNote::recordFor($invoice->fresh(), 'AV-FOURN-009', now()->toDateString(), 300, 0, 300);

        $this->assertNotNull($creditNote->id);
    }

    public function test_lutilisateur_authentifie_est_renseigne_automatiquement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $invoice = $this->makeSupplierInvoice(1000);
        $creditNote = SupplierCreditNote::recordFor($invoice, 'AV-FOURN-010', now()->toDateString(), 500, 0, 500);

        $this->assertSame($user->id, $creditNote->user_id);
    }

    /*
     * =================================================================
     * Immuabilité (décision validée : aucune exception)
     * =================================================================
     */

    public function test_un_avoir_ne_peut_pas_etre_modifie(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $creditNote = SupplierCreditNote::recordFor($invoice, 'AV-FOURN-011', now()->toDateString(), 500, 0, 500);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être modifié');

        $creditNote->update(['total_ttc' => 999]);
    }

    public function test_un_avoir_ne_peut_pas_etre_supprime(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);
        $creditNote = SupplierCreditNote::recordFor($invoice, 'AV-FOURN-012', now()->toDateString(), 500, 0, 500);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ne peut pas être supprimé');

        $creditNote->delete();
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
        $creditNote = SupplierCreditNote::recordFor($invoice, 'AV-FOURN-013', now()->toDateString(), 500, 0, 500);

        $this->assertTrue($creditNote->supplierInvoice->is($invoice));
        $this->assertTrue($creditNote->user->is($user));
        $this->assertTrue($invoice->fresh()->creditNotes->contains($creditNote));
    }

    /*
     * =================================================================
     * totalCreditedFor() — source de vérité unique
     * =================================================================
     */

    public function test_totalcreditedfor_reflete_le_cumul_reel(): void
    {
        $invoice = $this->makeSupplierInvoice(1000);

        $this->assertSame(0.0, SupplierCreditNote::totalCreditedFor($invoice));

        SupplierCreditNote::recordFor($invoice, 'AV-FOURN-014', now()->toDateString(), 250, 0, 250);
        $this->assertSame(250.0, SupplierCreditNote::totalCreditedFor($invoice->fresh()));

        SupplierCreditNote::recordFor($invoice->fresh(), 'AV-FOURN-015', now()->toDateString(), 250, 0, 250);
        $this->assertSame(500.0, SupplierCreditNote::totalCreditedFor($invoice->fresh()));
    }

    /*
     * =================================================================
     * Précision monétaire
     * =================================================================
     */

    /**
     * Séquence de montants connus pour révéler une dérive flottante
     * naïve en PHP (type 0,1 + 0,2 != 0,3 en IEEE754, ici à l'échelle
     * monétaire) : la somme calculée côté base et les comparaisons
     * arrondies doivent rester exactes au centime près.
     */
    public function test_la_precision_des_montants_ne_derive_pas_avec_des_avoirs_flottants_delicats(): void
    {
        // 0.10 + 0.20 + 0.30 = 0.60 exactement en décimal, mais une
        // addition flottante naïve en PHP peut produire 0.6000000000000001.
        $invoice = $this->makeSupplierInvoice(0.60);

        SupplierCreditNote::recordFor($invoice, 'AV-FOURN-016', now()->toDateString(), 0.10, 0, 0.10);
        SupplierCreditNote::recordFor($invoice->fresh(), 'AV-FOURN-017', now()->toDateString(), 0.20, 0, 0.20);
        SupplierCreditNote::recordFor($invoice->fresh(), 'AV-FOURN-018', now()->toDateString(), 0.30, 0, 0.30);

        $this->assertSame(0.6, SupplierCreditNote::totalCreditedFor($invoice->fresh()));
    }

    /**
     * Autre cas classique de dérive flottante : 3 avoirs de 33.33 € ne
     * doivent jamais, à cause d'un résidu flottant, être interprétés
     * comme dépassant 100,00 € (33.33 * 3 = 99.99, pas 100 — le
     * dernier centime doit rester acceptable comme avoir final).
     */
    public function test_la_precision_des_montants_narrondit_pas_a_tort_un_avoir_final_legitime(): void
    {
        $invoice = $this->makeSupplierInvoice(100);

        SupplierCreditNote::recordFor($invoice, 'AV-FOURN-019', now()->toDateString(), 33.33, 0, 33.33);
        SupplierCreditNote::recordFor($invoice->fresh(), 'AV-FOURN-020', now()->toDateString(), 33.33, 0, 33.33);
        SupplierCreditNote::recordFor($invoice->fresh(), 'AV-FOURN-021', now()->toDateString(), 33.33, 0, 33.33);
        // Reste exactement 0.01 : doit être accepté, pas rejeté par une
        // dérive flottante qui ferait apparaître le cumul déjà à 100.00
        // ou légèrement au-delà.
        $creditNote = SupplierCreditNote::recordFor($invoice->fresh(), 'AV-FOURN-022', now()->toDateString(), 0.01, 0, 0.01);

        $this->assertNotNull($creditNote->id);
        $this->assertSame(100.0, SupplierCreditNote::totalCreditedFor($invoice->fresh()));
    }

    /*
     * =================================================================
     * Concurrence / atomicité
     * =================================================================
     */

    /**
     * Facture à 1000 €, deux tentatives simultanées de 600 € — un seul
     * avoir doit être accepté, le total final ne doit jamais dépasser
     * 1000 €. Même protocole que SupplierInvoicePaymentTest (T30).
     */
    public function test_deux_avoirs_concurrents_de_600_sur_une_facture_de_1000_un_seul_est_accepte(): void
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
                \\App\\Models\\SupplierCreditNote::recordFor(\$invoice, 'AV-CONC-'.uniqid(), now()->toDateString(), 600, 0, 600);
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

        $this->assertSame(1, $accepted, 'Exactement un des deux avoirs concurrents doit être accepté.');
        $this->assertSame(1, $rejected, "L'autre doit être rejeté (dépassement du total TTC).");

        $totalCreditedInIsolatedDb = $this->sumCreditNotesInIsolatedDatabase($dbFile, $invoiceId);
        $this->assertLessThanOrEqual(1000.0, $totalCreditedInIsolatedDb, 'Le total réellement persisté ne doit jamais dépasser 1000 €.');
        $this->assertSame(600.0, $totalCreditedInIsolatedDb, 'Un seul avoir de 600 € doit avoir été persisté.');
    }

    /**
     * Démonstration renforcée (au-delà du strict minimum) — 10
     * processus concurrents tentent chacun un avoir de 200 € sur une
     * facture de 1000 € : exactement 5 doivent réussir, jamais 6,
     * jamais un total final supérieur à 1000 €.
     */
    public function test_dix_avoirs_concurrents_de_200_sur_une_facture_de_1000_exactement_cinq_sont_acceptes(): void
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
                \\App\\Models\\SupplierCreditNote::recordFor(\$invoice, 'AV-CONC-'.uniqid(), now()->toDateString(), 200, 0, 200);
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
        $this->assertSame(5, $accepted, 'Exactement 5 avoirs de 200 € doivent être acceptés (5 x 200 = 1000).');

        $totalCreditedInIsolatedDb = $this->sumCreditNotesInIsolatedDatabase($dbFile, $invoiceId);
        $this->assertSame(1000.0, $totalCreditedInIsolatedDb, 'Le total final doit être strictement égal à 1000 €, jamais davantage.');
    }

    /**
     * Prépare une base SQLite isolée et jetable, migrée fraîchement,
     * contenant une unique SupplierInvoice du montant demandé — même
     * technique Windows-safe (proc_open() + tableau $env explicite) que
     * SupplierInvoicePaymentTest (T30).
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function prepareIsolatedDatabaseWithInvoice(float $totalTtc): array
    {
        $scratchDir = sys_get_temp_dir().'/supplier_credit_note_test_'.uniqid();
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

    private function sumCreditNotesInIsolatedDatabase(string $dbFile, int $invoiceId): float
    {
        $pdo = new \PDO('sqlite:'.$dbFile);
        $stmt = $pdo->query("SELECT COALESCE(SUM(total_ttc), 0) FROM supplier_credit_notes WHERE supplier_invoice_id = {$invoiceId}");

        return round((float) $stmt->fetchColumn(), 2);
    }
}
