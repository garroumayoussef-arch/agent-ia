<?php

namespace Tests\Feature;

use App\Models\CompanySettings;
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
use Tests\TestCase;

/**
 * Chantier "Notifications & communication" V1 (D8, validé) — le journal
 * `notification_logs` est LE mécanisme d'anti-duplication (jamais un
 * simple contrôle applicatif) : réservation AVANT tout envoi, contrainte
 * UNIQUE en base comme rempart final. Même défense en profondeur que
 * celle déjà validée sur invoices.vtc_ride_id (chantier VTC).
 */
class NotificationAntiDuplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_une_seconde_reservation_identique_retourne_null(): void
    {
        $customer = Customer::create(['name' => 'Client', 'customer_type' => Customer::TYPE_INDIVIDUAL, 'country' => 'France']);

        $first = NotificationLog::reserve($customer, 'test_event', 'email', 'client@example.test');
        $second = NotificationLog::reserve($customer, 'test_event', 'email', 'client@example.test');

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(
            1,
            NotificationLog::where('notifiable_type', Customer::class)
                ->where('notifiable_id', $customer->id)
                ->where('event_type', 'test_event')
                ->count(),
        );
    }

    /**
     * occurrence_key distinct (ex. deux jours différents pour un
     * digest) : autorisé, jamais bloqué par la contrainte UNIQUE — ce
     * n'est PAS un doublon du même événement.
     */
    public function test_une_reservation_avec_occurrence_key_differente_est_autorisee(): void
    {
        $customer = Customer::create(['name' => 'Client', 'customer_type' => Customer::TYPE_INDIVIDUAL, 'country' => 'France']);

        $first = NotificationLog::reserve($customer, 'test_event', 'email', 'client@example.test', occurrenceKey: '2026-08-28');
        $second = NotificationLog::reserve($customer, 'test_event', 'email', 'client@example.test', occurrenceKey: '2026-08-29');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(2, NotificationLog::count());
    }

    /**
     * D5/D8 (validés) — une adresse inconnue (null) est réservée au
     * statut 'failed' directement, jamais 'queued' : l'appelant ne
     * doit jamais tenter d'envoi dans ce cas (cf. les 4 méthodes
     * generateFrom*()/recordFor() qui vérifient ce statut).
     */
    public function test_une_reservation_sans_adresse_connue_est_marquee_echec_immediatement(): void
    {
        $customer = Customer::create(['name' => 'Client', 'customer_type' => Customer::TYPE_INDIVIDUAL, 'country' => 'France']);

        $log = NotificationLog::reserve($customer, 'test_event', 'email', null);

        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
    }

    /**
     * D8 (validé) — démonstration de concurrence RÉELLE (processus OS
     * indépendants, même méthodologie que
     * VtcRideInvoiceGenerationTest::test_plusieurs_processus_concurrents_...()
     * du chantier VTC) : N réservations strictement simultanées pour LE
     * MÊME événement ne doivent jamais produire plus d'une seule ligne
     * de journal au statut exploitable.
     */
    public function test_plusieurs_processus_concurrents_ne_reservent_jamais_deux_fois_le_meme_evenement(): void
    {
        $scratchDir = sys_get_temp_dir().'/notif_log_concurrency_'.uniqid();
        mkdir($scratchDir);
        $dbFile = $scratchDir.'/concurrency.sqlite';
        $resultsFile = $scratchDir.'/results.txt';
        $setupFile = $scratchDir.'/setup.php';
        $probeFile = $scratchDir.'/probe.php';
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

        file_put_contents($setupFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \$customer = \App\Models\Customer::create(['name' => 'Client', 'customer_type' => 'individual', 'country' => 'France']);
            echo \$customer->id;
            PHP);

        $setupProcess = proc_open(
            ['php', $setupFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $setupPipes,
            $basePath,
            $envOverrides,
        );
        $customerId = trim(stream_get_contents($setupPipes[1]));
        $setupError = stream_get_contents($setupPipes[2]);
        $setupStatus = proc_close($setupProcess);
        $this->assertSame(0, $setupStatus, 'La préparation de la base isolée a échoué : '.$setupError);
        $this->assertNotSame('', $customerId);

        file_put_contents($probeFile, <<<PHP
            <?php
            require '{$basePath}/vendor/autoload.php';
            \$app = require '{$basePath}/bootstrap/app.php';
            \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

            \$customer = \App\Models\Customer::find((int) \$argv[2]);
            \$log = \App\Models\NotificationLog::reserve(\$customer, 'concurrency_test', 'email', 'client@example.test');
            \$result = \$log !== null ? 'RESERVED' : 'SKIPPED';

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
                ['php', $probeFile, $resultsFile, $customerId],
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
        $this->assertCount($processCount, $results);

        $reservedCount = count(array_filter($results, fn (string $r): bool => $r === 'RESERVED'));
        $this->assertSame(1, $reservedCount, 'Exactement une réservation aurait dû réussir.');

        $pdo = new \PDO('sqlite:'.$dbFile);
        $count = $pdo->query('SELECT COUNT(*) FROM notification_logs')->fetchColumn();
        $this->assertSame('1', (string) $count);
    }
}
