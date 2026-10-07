<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use PDO;
use RuntimeException;
use Throwable;

/** Full inherited matrix, real disposable PostgreSQL required; no skip/.env. */
class ProductAttributeDirectWritePostgresTest extends ProductAttributeDirectWriteTest
{
    protected function openConnection(): Connection
    {
        $settings = [];
        foreach (['HOST', 'PORT', 'DATABASE', 'USER', 'PASSWORD', 'DISPOSABLE_DATABASE'] as $key) {
            $settings[$key] = getenv('D2_6_27_PG_'.$key);
        }
        if (! in_array($settings['HOST'], ['127.0.0.1', '::1'], true)
            || ! is_string($settings['PORT']) || ! ctype_digit($settings['PORT'])
            || (int) $settings['PORT'] < 1 || (int) $settings['PORT'] > 65535
            || ! is_string($settings['DATABASE'])
            || preg_match('/\Amagarrou_2627_disposable_[a-z0-9_]{1,30}\z/', $settings['DATABASE']) !== 1
            || $settings['DATABASE'] !== $settings['DISPOSABLE_DATABASE']
            || $settings['DATABASE'] === 'magarrou_restore_test_20261005'
            || ! is_string($settings['USER']) || $settings['USER'] === ''
            || ! is_string($settings['PASSWORD'])
            || ! in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Configuration PostgreSQL locale jetable 2.6.27 explicite requise.');
        }
        $connection = null;
        try {
            $pdo = new PDO('pgsql:host='.$settings['HOST'].';port='.$settings['PORT'].';dbname='.$settings['DATABASE'].';connect_timeout=3',
                $settings['USER'], $settings['PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false]);
            $connection = new PostgresConnection($pdo, $settings['DATABASE'], '', ['driver' => 'pgsql']);
            $identity = $connection->selectOne('SELECT current_database() AS database, current_user AS username, host(inet_server_addr()) AS address, inet_server_port() AS port');
            if ($identity->database !== $settings['DATABASE'] || $identity->username !== $settings['USER']
                || ! in_array($identity->address, ['127.0.0.1', '::1'], true) || (int) $identity->port !== (int) $settings['PORT']) {
                throw new RuntimeException();
            }
            $connection->beginTransaction();
            $lock = $connection->selectOne('SELECT pg_catalog.pg_try_advisory_xact_lock(2627, 100000) AS acquired');
            $relations = $connection->selectOne("SELECT count(*) AS count FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname <> 'information_schema' AND left(n.nspname, 3) <> 'pg_'");
            if (! in_array($lock->acquired, [true, 1, '1', 't'], true) || (int) $relations->count !== 0) {
                throw new RuntimeException();
            }
            $connection->statement('SET LOCAL search_path TO public, pg_catalog');
            $connection->statement("SET LOCAL lock_timeout = '2s'");

            return $connection;
        } catch (Throwable) {
            try {
                if ($connection !== null && $connection->transactionLevel() > 0) {
                    $connection->rollBack(0);
                }
            } catch (Throwable) {
                throw new RuntimeException('Nettoyage de connexion PostgreSQL refusé : détails masqués.');
            }
            throw new RuntimeException('Connexion, identité ou disponibilité PostgreSQL jetable refusée : détails masqués.');
        }
    }
}
