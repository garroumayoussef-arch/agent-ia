<?php

namespace Tests\Feature;

use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Deux processus indépendants, barrières explicites dans un dossier temporaire.
 * Schéma réduit avec la FK immédiate réelle allocation -> sourcing, sans
 * migration applicative. PostgreSQL exige une base de TEST dédiée fournie par
 * D2_20_PG_DSN, D2_20_PG_USER et D2_20_PG_PASSWORD. Absence = échec, pas skip.
 */
class SupplierProductSourcingIdentityConcurrencyTest extends TestCase
{
    public function test_courses_sqlite_reelles(): void
    {
        $this->exerciseRaces('sqlite');
    }

    public function test_courses_postgresql_obligatoires(): void
    {
        $this->assertContains('pgsql', PDO::getAvailableDrivers(), 'D2.20 : pdo_pgsql requis pour valider la concurrence PostgreSQL.');
        $this->assertNotEmpty(getenv('D2_20_PG_DSN'), 'D2.20 : D2_20_PG_DSN doit désigner une base PostgreSQL de test dédiée.');
        $this->exerciseRaces('pgsql');
    }

    private function exerciseRaces(string $driver): void
    {
        foreach ([['allocate', 'commit'], ['allocate', 'rollback'], ['edit', 'commit'], ['edit', 'rollback']] as [$first, $finish]) {
            $directory = sys_get_temp_dir().'/d220_'.bin2hex(random_bytes(8));
            mkdir($directory);
            $schema = basename($directory);
            $settings = [
                'driver' => $driver,
                'dsn' => $driver === 'sqlite' ? 'sqlite:'.$directory.'/test.sqlite' : getenv('D2_20_PG_DSN'),
                'user' => $driver === 'sqlite' ? null : (getenv('D2_20_PG_USER') ?: null),
                'password' => $driver === 'sqlite' ? null : (getenv('D2_20_PG_PASSWORD') ?: null),
                'schema' => $schema,
            ];
            $pdo = null;
            $createdSchema = false;
            $workers = [];
            try {
                $pdo = new PDO($settings['dsn'], $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                if ($driver === 'pgsql') {
                    $pdo->exec('CREATE SCHEMA '.$schema);
                    $createdSchema = true;
                    $pdo->exec('SET search_path TO '.$schema);
                } else {
                    $pdo->exec('PRAGMA foreign_keys = ON');
                    $pdo->exec('PRAGMA journal_mode = WAL');
                }
                $pdo->exec('CREATE TABLE supplier_product_sourcing (id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, product_id INTEGER NOT NULL, product_variant_id INTEGER NULL, notes TEXT NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
                $pdo->exec('CREATE TABLE sales_order_item_allocations (id INTEGER PRIMARY KEY, supplier_product_sourcing_id INTEGER NOT NULL REFERENCES supplier_product_sourcing(id) ON DELETE RESTRICT, quantity INTEGER NOT NULL)');
                $pdo->exec("INSERT INTO supplier_product_sourcing (id, supplier_id, product_id, notes) VALUES (1, 10, 20, 'initial')");
                $workers[] = $holder = $this->worker($settings, $first, $directory.'/holder', true);
                $this->awaitBarrier($holder, $directory.'/holder', 'held');
                $workers[] = $contender = $this->worker($settings, $first === 'allocate' ? 'edit' : 'allocate', $directory.'/contender');
                $this->awaitBarrier($contender, $directory.'/contender', 'attempting');
                if ($driver === 'pgsql') {
                    $deadline = microtime(true) + 5;
                    do {
                        $waiting = (int) $pdo->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name = '".$schema."_contender' AND wait_event_type = 'Lock'")->fetchColumn();
                        if ($waiting > 0) { break; }
                        usleep(10000);
                    } while (microtime(true) < $deadline && $contender->isRunning());
                    $this->assertGreaterThan(0, $waiting, 'Le concurrent doit attendre le verrou FK/FOR UPDATE.');
                } else {
                    usleep(150000);
                }
                file_put_contents($directory.'/holder.release', $finish);
                $this->assertSame($finish, $this->workerResult($holder));
                $outcome = $this->workerResult($contender);
                $this->assertContains($outcome, ['committed', 'refused', 'conflict']);
                $row = $pdo->query('SELECT supplier_id, notes FROM supplier_product_sourcing WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
                $count = (int) $pdo->query('SELECT COUNT(*) FROM sales_order_item_allocations')->fetchColumn();
                if ($first === 'allocate' && $finish === 'commit') {
                    $this->assertContains($outcome, ['refused', 'conflict']);
                    $this->assertSame(10, (int) $row['supplier_id']);
                    $this->assertSame('initial', $row['notes']);
                    $this->assertSame(1, $count);
                } elseif ($first === 'edit') {
                    $this->assertSame($finish === 'commit' ? 11 : 10, (int) $row['supplier_id']);
                    $this->assertSame($finish === 'commit' ? 'edited' : 'initial', $row['notes']);
                    $this->assertSame('committed', $outcome);
                    $this->assertSame(1, $count);
                } else {
                    $this->assertSame(0, $count);
                    if ($outcome === 'conflict') {
                        // Nouvelle commande complète, jamais UPDATE seul.
                        $workers[] = $retry = $this->worker($settings, 'edit', $directory.'/retry');
                        $this->assertSame('committed', $this->workerResult($retry));
                    } else {
                        $this->assertSame('committed', $outcome);
                    }
                    $this->assertSame(11, (int) $pdo->query('SELECT supplier_id FROM supplier_product_sourcing')->fetchColumn());
                }
                if ($count === 1) {
                    $workers[] = $again = $this->worker($settings, 'edit-again', $directory.'/again');
                    $this->assertSame('refused', $this->workerResult($again));
                }
                if ($driver === 'pgsql') {
                    $workers[] = $isolation = $this->worker($settings, 'isolation', $directory.'/isolation');
                    $this->assertSame('isolation-refused', $this->workerResult($isolation));
                    $workers[] = $operational = $this->worker($settings, 'operational-isolation', $directory.'/operational');
                    $this->assertSame('committed', $this->workerResult($operational));
                }
            } finally {
                foreach ($workers as $worker) { $worker->stop(0); }
                if ($createdSchema) { $pdo->exec('DROP SCHEMA '.$schema.' CASCADE'); }
                $pdo = null;
                // Uniquement les fichiers du dossier aléatoire créé ici.
                foreach (glob($directory.'/*') as $temporary) { unlink($temporary); }
                rmdir($directory);
            }
        }
    }

    private function worker(array $settings, string $operation, string $barrier, bool $hold = false): Process
    {
        $script = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $settings = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $operation = $argv[1];
        $barrier = $argv[2];
        $hold = $argv[3] === '1';
        $pdo = new PDO($settings['dsn'], $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $driver = $settings['driver'];
        if ($driver === 'pgsql') {
            $pdo->exec('SET search_path TO '.$settings['schema']);
            $pdo->exec("SET lock_timeout = '5s'");
            $pdo->exec("SET application_name = '".$settings['schema'].'_'.basename($barrier)."'");
            $pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $connection = new Illuminate\Database\PostgresConnection($pdo, 'd220', '', ['driver' => 'pgsql', 'name' => 'd220']);
        } else {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $connection = new Illuminate\Database\SQLiteConnection($pdo, 'd220', '', ['driver' => 'sqlite', 'name' => 'd220']);
        }
        $resolver = new Illuminate\Database\ConnectionResolver(['d220' => $connection]);
        $resolver->setDefaultConnection('d220');
        Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
        $container = new Illuminate\Container\Container;
        $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'fr');
        $container->instance('validator', new Illuminate\Validation\Factory($translator, $container));
        Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        $connection->beginTransaction();
        if (str_contains($operation, 'isolation')) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            if ($operation === 'allocate') {
                file_put_contents($barrier, 'attempting');
                $connection->table('sales_order_item_allocations')->insert(['id' => 1, 'supplier_product_sourcing_id' => 1, 'quantity' => 1]);
            } else {
                $source = App\Models\SupplierProductSourcing::on('d220')->findOrFail(1);
                file_put_contents($barrier, 'attempting');
                $data = ['notes' => 'edited'];
                if ($operation !== 'operational-isolation') {
                    $data['supplier_id'] = $operation === 'edit' ? 11 : 12;
                }
                $source->update($data);
            }
            if ($hold) {
                file_put_contents($barrier, 'held');
                $deadline = microtime(true) + 10;
                while (! is_file($barrier.'.release')) {
                    if (microtime(true) > $deadline) { throw new RuntimeException('Barrière non libérée.'); }
                    clearstatcache(true, $barrier.'.release');
                    usleep(10000);
                }
                $finish = trim(file_get_contents($barrier.'.release'));
                $finish === 'commit' ? $connection->commit() : $connection->rollBack();
                echo $finish;
            } else {
                $connection->commit();
                echo 'committed';
            }
        } catch (Illuminate\Validation\ValidationException $e) {
            $connection->rollBack();
            echo str_contains($e->getMessage(), 'READ COMMITTED') ? 'isolation-refused' : 'refused';
        } catch (Throwable $e) {
            $connection->rollBack();
            if ($driver === 'sqlite'
                && ($e instanceof Illuminate\Database\QueryException || $e instanceof Illuminate\Database\DeadlockException)
                && str_contains(strtolower($e->getMessage()), 'locked')) {
                echo 'conflict';
            } else {
                fwrite(STDERR, get_class($e));
                exit(2);
            }
        }
        PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $operation, $barrier, $hold ? '1' : '0'], base_path());
        // Secrets via stdin, jamais arguments de ligne de commande.
        $process->setInput(json_encode($settings, JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        $process->start();

        return $process;
    }

    private function awaitBarrier(Process $process, string $path, string $expected): void
    {
        $deadline = microtime(true) + 10;
        do {
            clearstatcache(true, $path);
            if (is_file($path) && file_get_contents($path) === $expected) {
                $this->assertTrue(true);
                return;
            }
            if (! $process->isRunning()) {
                $this->fail('Processus terminé avant la barrière '.$expected.' (code '.$process->getExitCode().').');
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Délai dépassé pour la barrière '.$expected.'.');
    }

    private function workerResult(Process $process): string
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), 'Le processus de concurrence a échoué : '.$process->getErrorOutput());

        return trim($process->getOutput());
    }
}
