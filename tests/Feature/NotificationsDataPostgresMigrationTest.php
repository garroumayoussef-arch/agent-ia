<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Sans bootstrap Laravel/.env, RefreshDatabase, migration globale ou CREATE DATABASE.
 * PostgreSQL obligatoire pour une exécution complète : configuration absente = échec.
 * Fournir D2_6_25_PG_HOST, PORT, DATABASE, USER, PASSWORD et DISPOSABLE_DATABASE.
 * DISPOSABLE_DATABASE doit répéter exactement le nom explicitement autorisé.
 * Le nom doit commencer par magarrou_2625_disposable_ ; aucun nom n'est inventé ici.
 */
class NotificationsDataPostgresMigrationTest extends TestCase
{
    private const ERROR = 'La conversion des notifications a été refusée.';

    private mixed $previousFacadeApplication;

    private mixed $previousResolver;

    private string|false $previousIgnoreArgs;

    private ?PostgresConnection $guardedConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousIgnoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '1');
        if (ini_get('zend.exception_ignore_args') !== '1') {
            throw new RuntimeException('Masquage des arguments requis avant les tests.');
        }
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
    }

    protected function tearDown(): void
    {
        if ($this->guardedConnection !== null) {
            try {
                $this->guardedConnection->selectOne('SELECT pg_catalog.pg_advisory_unlock(2625, 100000)');
            } catch (Throwable) {
                throw new RuntimeException('Nettoyage PostgreSQL jetable impossible : détails masqués.');
            }
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        parent::tearDown();
        if ($this->previousIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs);
        }
    }

    private function bind(Connection $connection): void
    {
        $resolver = new ConnectionResolver(['checkpoint2625' => $connection]);
        $resolver->setDefaultConnection('checkpoint2625');
        $container = new Container;
        $container->instance('db', $resolver);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Model::setConnectionResolver($resolver);
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_10_05_100000_convert_notifications_data_to_json_for_postgresql.php';
    }

    public function test_sqlite_up_and_down_preserve_schema_and_data(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
        $connection->statement('CREATE TABLE notifications (id TEXT PRIMARY KEY, data TEXT NOT NULL)');
        $connection->table('notifications')->insert(['id' => 'sqlite', 'data' => 'not json']);
        $before = $connection->select("SELECT sql FROM sqlite_master WHERE name = 'notifications'");
        $this->bind($connection);
        $connection->enableQueryLog();
        $this->migration()->up();
        $this->migration()->down();
        $this->assertSame([], $connection->getQueryLog());
        $this->assertEquals($before, $connection->select("SELECT sql FROM sqlite_master WHERE name = 'notifications'"));
        $this->assertSame('not json', $connection->table('notifications')->value('data'));
    }

    public static function otherDrivers(): array
    {
        return [['mysql'], ['mariadb'], ['sqlsrv']];
    }

    #[DataProvider('otherDrivers')]
    public function test_other_drivers_never_open_a_connection(string $driver): void
    {
        $connection = new Connection(static function (): never {
            throw new RuntimeException('Connexion interdite dans ce test.');
        }, '', '', ['driver' => $driver]);
        $this->bind($connection);
        $connection->enableQueryLog();
        $this->migration()->up();
        $this->migration()->down();
        $this->assertSame([], $connection->getQueryLog());
    }

    public static function unsafeSettings(): array
    {
        return [
            'absent' => [[]],
            'remote' => [['host' => 'remote.invalid']],
            'restored copy' => [['database' => 'magarrou_restore_test_20261005', 'confirmation' => 'magarrou_restore_test_20261005']],
            'ordinary database' => [['database' => 'magarrou', 'confirmation' => 'magarrou']],
            'missing confirmation' => [['confirmation' => '']],
            'wrong confirmation' => [['confirmation' => 'magarrou_2625_disposable_other']],
            'invalid port' => [['port' => '0']],
            'dsn injection' => [['database' => 'magarrou_2625_disposable_x;host=remote']],
        ];
    }

    #[DataProvider('unsafeSettings')]
    public function test_unsafe_postgresql_settings_are_refused_before_connecting(array $overrides): void
    {
        $settings = $overrides === [] ? [] : array_replace([
            'host' => '127.0.0.1', 'port' => '5432',
            'database' => 'magarrou_2625_disposable_fixture',
            'confirmation' => 'magarrou_2625_disposable_fixture',
            'user' => 'fixture', 'password' => 'sensitive-fixture',
        ], $overrides);
        try {
            $this->validateSettings($settings);
            $this->fail('Une configuration non autorisée a été acceptée.');
        } catch (RuntimeException $e) {
            $this->assertSame('Configuration PostgreSQL jetable explicite requise ou cible refusée.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('sensitive-fixture', (string) $e);
        }
    }

    private function validateSettings(array $settings): array
    {
        $valid = count(array_intersect(['host', 'port', 'database', 'confirmation', 'user', 'password'], array_keys($settings))) === 6;
        $valid = $valid && in_array($settings['host'], ['127.0.0.1', '::1'], true)
            && is_string($settings['port']) && ctype_digit($settings['port'])
            && (int) $settings['port'] >= 1 && (int) $settings['port'] <= 65535
            && is_string($settings['database'])
            && preg_match('/\Amagarrou_2625_disposable_[a-z0-9_]{1,30}\z/', $settings['database']) === 1
            && $settings['database'] !== 'magarrou_restore_test_20261005'
            && $settings['confirmation'] === $settings['database']
            && is_string($settings['user']) && $settings['user'] !== ''
            && is_string($settings['password']);
        if (! $valid) {
            throw new RuntimeException('Configuration PostgreSQL jetable explicite requise ou cible refusée.');
        }

        return $settings;
    }

    private function postgresql(): PostgresConnection
    {
        $settings = [];
        foreach (['host', 'port', 'database', 'user', 'password', 'confirmation'] as $key) {
            $suffix = $key === 'confirmation' ? 'DISPOSABLE_DATABASE' : strtoupper($key);
            $value = getenv('D2_6_25_PG_'.$suffix);
            if ($value !== false) {
                $settings[$key] = $value;
            }
        }
        $settings = $this->validateSettings($settings);
        if (! in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Pilote PostgreSQL requis pour les tests obligatoires.');
        }

        try {
            $pdo = new PDO(
                'pgsql:host='.$settings['host'].';port='.$settings['port'].';dbname='.$settings['database'].';connect_timeout=3',
                $settings['user'], $settings['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false],
            );
            $connection = new PostgresConnection($pdo, $settings['database'], '', ['driver' => 'pgsql', 'name' => 'checkpoint2625']);
            $identity = $connection->selectOne(<<<'SQL'
                SELECT current_database() AS database, current_user AS username,
                       host(inet_server_addr()) AS address, inet_server_port() AS port,
                       current_setting('server_version_num')::integer AS version
                SQL);
            if ($identity->database !== $settings['database'] || $identity->username !== $settings['user']
                || ! in_array($identity->address, ['127.0.0.1', '::1'], true)
                || (int) $identity->port !== (int) $settings['port'] || (int) $identity->version < 180000) {
                throw new RuntimeException('Identité refusée.');
            }

            return $connection;
        } catch (Throwable) {
            throw new RuntimeException('Connexion ou identité PostgreSQL jetable refusée.');
        }
    }

    private function guardEmptyDatabase(PostgresConnection $connection): void
    {
        if ($this->guardedConnection === null) {
            $lock = $connection->selectOne('SELECT pg_catalog.pg_try_advisory_lock(2625, 100000) AS acquired');
            if (! in_array($lock->acquired, [true, 1, '1', 't'], true)) {
                throw new RuntimeException('Base jetable déjà utilisée par une autre exécution.');
            }
            $this->guardedConnection = $connection;
        }
        $relations = $connection->selectOne(<<<'SQL'
            SELECT count(*) AS count FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname <> 'information_schema' AND left(n.nspname, 3) <> 'pg_'
            SQL);
        if ((int) $relations->count !== 0) {
            throw new RuntimeException('La base jetable doit être vide avant les fixtures.');
        }
    }

    private function fixture(PostgresConnection $connection, string $definition = 'text NOT NULL'): void
    {
        // La définition vient exclusivement des cas fixes du test.
        $connection->statement('CREATE TABLE public.notifications (id uuid PRIMARY KEY, type text NOT NULL, notifiable_type text NOT NULL, notifiable_id bigint NOT NULL, data '.$definition.', read_at timestamp NULL, created_at timestamp NULL, updated_at timestamp NULL)');
        $connection->statement('CREATE INDEX notifications_fixture_notifiable ON public.notifications (notifiable_type, notifiable_id)');
    }

    private function insert(PostgresConnection $connection, int $id, string $data, int $user = 1, bool $read = false): void
    {
        $connection->table('public.notifications')->insert([
            'id' => sprintf('00000000-0000-0000-0000-%012d', $id), 'type' => 'fixture',
            'notifiable_type' => User::class, 'notifiable_id' => $user, 'data' => $data,
            'read_at' => $read ? '2026-01-01 00:00:00' : null,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function snapshot(PostgresConnection $connection): array
    {
        return [
            $connection->select('SELECT id, type, notifiable_type, notifiable_id, data::text AS data, read_at, created_at, updated_at FROM public.notifications ORDER BY id'),
            $connection->select("SELECT a.attname, a.atttypid, a.attnotnull, a.atthasdef, pg_get_expr(d.adbin, d.adrelid) AS default_value FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum WHERE a.attrelid = 'public.notifications'::regclass AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum"),
            $connection->select("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'public.notifications'::regclass ORDER BY conname"),
            $connection->select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'notifications' ORDER BY indexname"),
        ];
    }

    private function refusal(callable $operation): void
    {
        try {
            $operation();
            $this->fail('La migration devait refuser cette opération.');
        } catch (RuntimeException $e) {
            $this->assertSame(self::ERROR, $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('sensitive-fixture', (string) $e);
        }
    }

    public function test_postgresql_conversion_rejections_preservation_and_real_filament_counter(): void
    {
        $connection = $this->postgresql(); // Échec obligatoire si configuration absente, aucun skip.
        try {
            $this->guardEmptyDatabase($connection);
            $connection->statement("SET search_path TO public, pg_catalog");
            $connection->statement("SET lock_timeout = '2s'");
            $this->bind($connection);
            $connection->beginTransaction();

            $this->fixture($connection);
            $before = $this->snapshot($connection);
            $this->migration()->up();
            $this->assertSame('json', $connection->selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'notifications' AND column_name = 'data'")->data_type);
            $this->refusal(fn () => $this->migration()->up());
            $this->migration()->down();
            $this->assertEquals($before, $this->snapshot($connection));

            $values = [" { \"format\" : \"filament\", \"z\":1, \"a\":2, \"a\":3 } \n", '{"format":"filament"}', '{"format":"other"}', '{"title":"digest without format"}', 'null', '[1,true,"é"]', '"scalar"', '42', 'false'];
            foreach ($values as $key => $value) {
                $this->insert($connection, $key + 1, $value, $key === 1 ? 2 : 1);
            }
            $this->insert($connection, 20, '{"format":"filament"}', 1, true);
            $before = $this->snapshot($connection);
            $user = new User;
            $user->setConnection('checkpoint2625');
            $user->setRawAttributes(['id' => 1], true);
            $counter = new class($user) extends DatabaseNotifications
            {
                public function __construct(private User $fixtureUser) {}

                public function getUser(): User
                {
                    return $this->fixtureUser;
                }
            };
            try {
                $connection->transaction(fn () => $counter->getUnreadNotificationsCount());
                $this->fail('Le compteur sur text devait échouer.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertSame('42883', (string) $e->getCode());
            }
            $this->migration()->up();
            $this->assertSame(1, $counter->getUnreadNotificationsCount());
            $this->assertEquals($before[0], $this->snapshot($connection)[0]);
            $this->assertEquals(array_slice($before[1], 0, 4), array_slice($this->snapshot($connection)[1], 0, 4));
            $this->assertEquals($before[2], $this->snapshot($connection)[2]);
            $this->assertEquals($before[3], $this->snapshot($connection)[3]);
            $this->migration()->down();
            $this->assertEquals($before, $this->snapshot($connection));
            $this->migration()->up();
            $this->assertSame(1, $counter->getUnreadNotificationsCount());
            $this->migration()->down();
            $connection->statement('DROP TABLE public.notifications');

            // Une contrainte dépendant d'un opérateur text fait échouer ALTER TYPE.
            $this->fixture($connection);
            $connection->statement("ALTER TABLE public.notifications ADD CONSTRAINT checkpoint2625_text_check CHECK (data = '{}')");
            $this->insert($connection, 1, '{}');
            $before = $this->snapshot($connection);
            $this->refusal(fn () => $this->migration()->up());
            $this->assertEquals($before, $this->snapshot($connection));
            $connection->statement('DROP TABLE public.notifications');

            foreach (['text', "text NOT NULL DEFAULT '{}'", 'json NOT NULL', 'jsonb NOT NULL', 'varchar NOT NULL'] as $definition) {
                $this->fixture($connection, $definition);
                $before = $this->snapshot($connection);
                $this->refusal(fn () => $this->migration()->up());
                $this->assertEquals($before, $this->snapshot($connection));
                $connection->statement('DROP TABLE public.notifications');
            }
            foreach (['json', "json NOT NULL DEFAULT '{}'", 'jsonb NOT NULL', 'text NOT NULL'] as $definition) {
                $this->fixture($connection, $definition);
                $before = $this->snapshot($connection);
                $this->refusal(fn () => $this->migration()->down());
                $this->assertEquals($before, $this->snapshot($connection));
                $connection->statement('DROP TABLE public.notifications');
            }
            $this->fixture($connection);
            $connection->statement('ALTER TABLE public.notifications ENABLE ROW LEVEL SECURITY');
            $before = $this->snapshot($connection);
            $this->refusal(fn () => $this->migration()->up());
            $this->assertEquals($before, $this->snapshot($connection));
            $connection->statement('DROP TABLE public.notifications');
            foreach (['', '   ', '{"sensitive-fixture":', 'true false'] as $invalid) {
                $this->fixture($connection);
                $this->insert($connection, 1, '{"valid":true}');
                $this->insert($connection, 2, $invalid);
                $before = $this->snapshot($connection);
                $this->refusal(fn () => $this->migration()->up());
                $this->assertEquals($before, $this->snapshot($connection));
                $connection->statement('DROP TABLE public.notifications');
            }
            $this->fixture($connection, 'text');
            $connection->statement("INSERT INTO public.notifications (id, type, notifiable_type, notifiable_id, data) VALUES ('00000000-0000-0000-0000-000000000001', 'fixture', 'fixture', 1, NULL)");
            $before = $this->snapshot($connection);
            $this->refusal(fn () => $this->migration()->up());
            $this->assertEquals($before, $this->snapshot($connection));
            $connection->statement('DROP TABLE public.notifications');
            $this->refusal(fn () => $this->migration()->up());
            $this->refusal(fn () => $this->migration()->down());

            $connection->statement('CREATE TABLE public.notifications (id integer)');
            $this->refusal(fn () => $this->migration()->up());
            $connection->statement('DROP TABLE public.notifications');
            $connection->statement('CREATE SCHEMA checkpoint2625_wrong');
            $connection->statement('CREATE TABLE checkpoint2625_wrong.notifications (data text NOT NULL)');
            $this->refusal(fn () => $this->migration()->up());
            $connection->statement('DROP SCHEMA checkpoint2625_wrong CASCADE');

            // Domaine, masquage par search_path et états down inattendus.
            $connection->statement('CREATE DOMAIN public.checkpoint2625_text AS text');
            $this->fixture($connection, 'public.checkpoint2625_text NOT NULL');
            $this->refusal(fn () => $this->migration()->up());
            $connection->statement('DROP TABLE public.notifications');
            $connection->statement('DROP DOMAIN public.checkpoint2625_text');
            $this->fixture($connection);
            $connection->statement('CREATE SCHEMA checkpoint2625_shadow');
            $connection->statement('CREATE TABLE checkpoint2625_shadow.notifications (data text NOT NULL)');
            $connection->statement('SET LOCAL search_path TO checkpoint2625_shadow, public, pg_catalog');
            $this->refusal(fn () => $this->migration()->up());
            $connection->statement('SET LOCAL search_path TO public, pg_catalog');
            $this->refusal(fn () => $this->migration()->down());
            $this->migration()->up();
            $connection->statement('ALTER TABLE public.notifications ALTER COLUMN data DROP NOT NULL');
            $before = $this->snapshot($connection);
            $this->refusal(fn () => $this->migration()->down());
            $this->assertEquals($before, $this->snapshot($connection));
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (Throwable) {
            $this->fail('Échec PostgreSQL : détails techniques et paramètres masqués.');
        } finally {
            if ($connection->transactionLevel() > 0) {
                $this->safeRollback($connection);
            }
        }
    }

    public function test_postgresql_exclusive_lock_blocks_a_concurrent_writer_and_rolls_back(): void
    {
        $connection = $this->postgresql();
        $other = $this->postgresql();
        $created = false;
        try {
            $this->guardEmptyDatabase($connection);
            $this->guardEmptyDatabase($other);
            $connection->statement('SET search_path TO public, pg_catalog');
            $other->statement("SET lock_timeout = '150ms'");
            $connection->transaction(fn () => $this->fixture($connection));
            $created = true;
            $this->bind($connection);
            $connection->beginTransaction();
            $this->migration()->up();
            try {
                $this->insert($other, 1, '{"format":"filament"}');
                $this->fail('Une écriture concurrente a contourné le verrou.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertSame('55P03', (string) $e->getCode());
            }
            $connection->rollBack();
            $this->insert($other, 1, '{"format":"filament"}');
            $this->assertSame(1, $other->table('public.notifications')->count());
            $this->assertSame('text', $connection->selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'notifications' AND column_name = 'data'")->data_type);
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (Throwable) {
            $this->fail('Échec du test de verrou PostgreSQL : détails masqués.');
        } finally {
            if ($connection->transactionLevel() > 0) {
                $this->safeRollback($connection);
            }
            if ($created) {
                try {
                    $connection->statement('DROP TABLE public.notifications');
                } catch (Throwable) {
                    $this->fail('Nettoyage de la fixture jetable impossible : détails masqués.');
                }
            }
        }
    }

    public function test_postgresql_missing_validator_and_late_failure_are_atomic(): void
    {
        $connection = $this->postgresql();
        $fault = null;
        try {
            $this->guardEmptyDatabase($connection);
            $connection->statement('SET search_path TO public, pg_catalog');
            $fault = new class($connection->getPdo(), $connection->getDatabaseName(), '', ['driver' => 'pgsql']) extends PostgresConnection
            {
                public string $fault = 'validator';

                public function selectOne($query, $bindings = [], $useReadPdo = true)
                {
                    if ($this->fault === 'validator' && str_contains($query, 'to_regprocedure')) {
                        return (object) ['available' => false];
                    }
                    $result = parent::selectOne($query, $bindings, $useReadPdo);
                    if ($this->fault === 'postcondition' && str_contains($query, 'AS expected') && $bindings === ['pg_catalog.json']) {
                        return (object) ['expected' => false];
                    }

                    return $result;
                }
            };
            // Une seule Connection possède la transaction ; les conversions utilisent ses savepoints.
            $fault->beginTransaction();
            $this->fixture($fault);
            $this->insert($fault, 1, '{"format":"filament"}');
            $before = $this->snapshot($fault);
            $this->bind($fault);
            $this->refusal(fn () => $this->migration()->up());
            $this->assertEquals($before, $this->snapshot($fault));
            $fault->fault = 'postcondition';
            $this->refusal(fn () => $this->migration()->up());
            $this->assertEquals($before, $this->snapshot($fault));
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (Throwable) {
            $this->fail('Échec des scénarios de rollback PostgreSQL : détails masqués.');
        } finally {
            if ($fault !== null && $fault->transactionLevel() > 0) {
                $this->safeRollback($fault);
            }
        }
    }

    private function safeRollback(PostgresConnection $connection): void
    {
        try {
            $connection->rollBack(0);
        } catch (Throwable) {
            throw new RuntimeException('Rollback PostgreSQL jetable impossible : détails masqués.');
        }
    }
}
