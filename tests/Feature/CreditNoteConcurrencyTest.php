<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Chantier "correctif de concurrence" (chantier A, validé) —
 * CreditNote::generateFromInvoice() n'avait jusqu'ici ni boucle de
 * nouvelle tentative, ni interception de la QueryException levée par
 * la contrainte UNIQUE (credit_note_lines.invoice_line_id) en cas de
 * sur-crédit réellement concurrent : une telle QueryException remontait
 * BRUTE à l'appelant (page Filament) au lieu du message métier habituel
 * ("Une ou plusieurs lignes sélectionnées ont déjà été créditées par un
 * avoir précédent.").
 *
 * Démonstration multi-processus/base isolée, même protocole que
 * InvoicePaymentTest/SupplierInvoicePaymentTest (T31/T30) : deux (puis
 * dix) processus OS réels appellent CreditNote::generateFromInvoice()
 * concurremment sur LA MÊME ligne d'une même facture. Attendu, dans
 * TOUS les cas :
 * - exactement UN succès (jamais deux CreditNoteLine pour la même
 *   InvoiceLine, quel que soit le nombre de tentatives concurrentes) ;
 * - tous les échecs reçoivent le message métier PROPRE (jamais une
 *   Illuminate\Database\QueryException brute).
 */
class CreditNoteConcurrencyTest extends TestCase
{
    /**
     * Prépare une base SQLite isolée et jetable, migrée fraîchement,
     * contenant une Invoice avec UNE SEULE InvoiceLine (celle que tous
     * les processus concurrents tenteront de créditer) — même technique
     * d'insertion SQL brute que InvoicePaymentTest::prepareIsolatedDatabaseWithInvoice().
     *
     * @return array{0: string, 1: string, 2: int, 3: int}
     */
    private function prepareIsolatedDatabaseWithInvoiceLine(float $lineTtc): array
    {
        $scratchDir = sys_get_temp_dir().'/credit_note_concurrency_test_'.uniqid();
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
        $pdo->exec("INSERT INTO sales_orders (reference, status, created_at, updated_at) VALUES ('CMD-AV-CONC', 'shipped', datetime('now'), datetime('now'))");
        $salesOrderId = (int) $pdo->lastInsertId();

        $pdo->exec(<<<SQL
            INSERT INTO invoices (
                sales_order_id, number, issued_at, sale_completed_at, operation_category,
                transaction_type, sales_order_reference, customer_type, customer_name,
                seller_legal_name, seller_address, seller_postal_code, seller_city, seller_country,
                seller_siren, vat_regime_snapshot, recovery_indemnity_amount_snapshot,
                total_ht, discount_amount, tax_amount, total_ttc, status, created_at, updated_at
            ) VALUES (
                {$salesOrderId}, 'FA-AV-CONC', date('now'), date('now'), 'vente',
                'b2c_domestic', 'CMD-AV-CONC', 'individual', 'Client Concurrence Avoir',
                'Magarrou', '1 rue du Sport', '75000', 'Paris', 'France',
                '111222333', 'standard', 40,
                {$lineTtc}, 0, 0, {$lineTtc}, 'issued', datetime('now'), datetime('now')
            )
            SQL);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->exec(<<<SQL
            INSERT INTO invoice_lines (invoice_id, product_name, quantity, unit_price_ht, subtotal_ht, tax_amount, total_ttc, created_at, updated_at)
            VALUES ({$invoiceId}, 'Ligne Concurrence Avoir', 1, {$lineTtc}, {$lineTtc}, 0, {$lineTtc}, datetime('now'), datetime('now'))
            SQL);
        $lineId = (int) $pdo->lastInsertId();

        return [$scratchDir, $dbFile, $invoiceId, $lineId];
    }

    private function countCreditNoteLinesInIsolatedDatabase(string $dbFile, int $invoiceLineId): int
    {
        $pdo = new \PDO('sqlite:'.$dbFile);
        $stmt = $pdo->query("SELECT COUNT(*) FROM credit_note_lines WHERE invoice_line_id = {$invoiceLineId}");

        return (int) $stmt->fetchColumn();
    }

    /**
     * Construit le script sonde exécuté par chaque processus concurrent
     * — catégorise explicitement le résultat pour distinguer un rejet
     * MÉTIER PROPRE (\Exception, message attendu) d'une fuite BRUTE
     * (Illuminate\Database\QueryException ou tout autre Throwable) —
     * c'est précisément cette distinction que le correctif doit
     * garantir.
     */
    private function writeProbeScript(string $probeFile, int $invoiceId, int $lineId): void
    {
        $basePath = base_path();

        file_put_contents($probeFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \$invoice = \\App\\Models\\Invoice::find({$invoiceId});
            \$result = 'FAILED';
            try {
                \\App\\Models\\CreditNote::generateFromInvoice(\$invoice, [{$lineId}], 'Concurrence', \\App\\Models\\CreditNote::SETTLEMENT_REFUND);
                \$result = 'ACCEPTED';
            } catch (\\Illuminate\\Database\\QueryException \$e) {
                // Fuite BRUTE — exactement le défaut que ce correctif
                // doit éliminer : jamais attendu, quel que soit le
                // nombre de processus concurrents.
                \$result = 'REJECTED_RAW';
            } catch (\\Exception \$e) {
                \$result = str_contains(\$e->getMessage(), 'créditée')
                    ? 'REJECTED_CLEAN'
                    : ('REJECTED_OTHER:'.\$e->getMessage());
            } catch (\\Throwable \$e) {
                \$result = 'REJECTED_RAW_OTHER:'.get_class(\$e);
            }

            \$fp = fopen(\$argv[1], 'a');
            flock(\$fp, LOCK_EX);
            fwrite(\$fp, \$result."\\n");
            flock(\$fp, LOCK_UN);
            fclose(\$fp);
            PHP);
    }

    /**
     * Scénario exact du défaut identifié : deux processus tentent
     * chacun de créditer la MÊME ligne au même instant. Un seul doit
     * réussir ; l'autre doit recevoir le message métier propre, jamais
     * une QueryException brute.
     */
    public function test_deux_generations_davoir_concurrentes_sur_la_meme_ligne_une_seule_est_acceptee(): void
    {
        [$scratchDir, $dbFile, $invoiceId, $lineId] = $this->prepareIsolatedDatabaseWithInvoiceLine(500);

        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();
        $resultsFile = $scratchDir.'/results.txt';
        $probeFile = $scratchDir.'/probe.php';
        $this->writeProbeScript($probeFile, $invoiceId, $lineId);

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
        $rejectedClean = count(array_filter($results, fn ($r) => $r === 'REJECTED_CLEAN'));
        $rejectedRaw = count(array_filter($results, fn ($r) => str_starts_with($r, 'REJECTED_RAW')));

        $this->assertSame(2, count($results), 'Les 2 processus doivent avoir répondu.');
        $this->assertSame(1, $accepted, 'Exactement un des deux avoirs concurrents doit être accepté.');
        $this->assertSame(1, $rejectedClean, "L'autre doit recevoir le message métier propre (déjà créditée).");
        $this->assertSame(0, $rejectedRaw, 'Aucune QueryException brute ne doit jamais remonter à l\'appelant.', );

        $this->assertSame(
            1,
            $this->countCreditNoteLinesInIsolatedDatabase($dbFile, $lineId),
            'Une seule CreditNoteLine doit exister pour cette InvoiceLine, jamais deux.',
        );
    }

    /**
     * Démonstration renforcée (au-delà du strict minimum) — 10
     * processus concurrents tentent tous de créditer la MÊME ligne :
     * exactement 1 doit réussir, les 9 autres doivent recevoir le
     * message métier propre, jamais une fuite brute, quelle que soit la
     * contention SQLite réelle sous 10 processus simultanés.
     */
    public function test_dix_generations_davoir_concurrentes_sur_la_meme_ligne_une_seule_est_acceptee(): void
    {
        [$scratchDir, $dbFile, $invoiceId, $lineId] = $this->prepareIsolatedDatabaseWithInvoiceLine(500);

        $envOverrides = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbFile] + getenv();
        $resultsFile = $scratchDir.'/results.txt';
        $probeFile = $scratchDir.'/probe.php';
        $this->writeProbeScript($probeFile, $invoiceId, $lineId);

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
        $rejectedClean = count(array_filter($results, fn ($r) => $r === 'REJECTED_CLEAN'));
        $rejectedRaw = count(array_filter($results, fn ($r) => str_starts_with($r, 'REJECTED_RAW')));

        $this->assertSame($processCount, count($results), 'Les 10 processus doivent avoir répondu.');
        $this->assertSame(1, $accepted, 'Exactement un des dix avoirs concurrents doit être accepté.');
        $this->assertSame($processCount - 1, $rejectedClean, 'Les 9 autres doivent recevoir le message métier propre.');
        $this->assertSame(0, $rejectedRaw, 'Aucune QueryException brute ne doit jamais remonter à l\'appelant, même sous forte contention.');

        $this->assertSame(
            1,
            $this->countCreditNoteLinesInIsolatedDatabase($dbFile, $lineId),
            'Une seule CreditNoteLine doit exister pour cette InvoiceLine, jamais deux.',
        );
    }
}
