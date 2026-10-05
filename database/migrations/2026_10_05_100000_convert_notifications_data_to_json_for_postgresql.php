<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->convert('text', 'json');
    }

    public function down(): void
    {
        // Ce retour arrière réintroduit l'incompatibilité de la cloche Filament.
        $this->convert('json', 'text');
    }

    private function convert(string $source, string $target): void
    {
        try {
            $connection = DB::connection($this->getConnection());

            if ($connection->getDriverName() !== 'pgsql') {
                return;
            }

            $connection->transaction(function () use ($connection, $source, $target): void {
                // Aucun contrôle préalable ne doit ouvrir une fenêtre de concurrence.
                $connection->statement('LOCK TABLE public.notifications IN ACCESS EXCLUSIVE MODE');
                $this->assertSchema($connection, $source);

                if ($source === 'text') {
                    $this->assertJsonInputSupport($connection);
                    $valid = $connection->selectOne(<<<'SQL'
                        SELECT COALESCE(bool_and(
                            data IS NOT NULL
                            AND pg_catalog.pg_input_is_valid(data, 'pg_catalog.json')
                        ), true) AS valid
                        FROM public.notifications
                        SQL);

                    if (! $this->isTrue($valid?->valid)) {
                        throw new RuntimeException('Conversion refusée.');
                    }
                }

                // SQL fixe : aucun identifiant ou contenu n'est interpolé.
                $connection->statement($target === 'json'
                    ? 'ALTER TABLE public.notifications ALTER COLUMN data TYPE json USING data::json'
                    : 'ALTER TABLE public.notifications ALTER COLUMN data TYPE text USING data::text');

                $this->assertSchema($connection, $target);
            });
        } catch (Throwable) {
            // Après rollback, ne conserver ni QueryException ni exception précédente.
            throw new RuntimeException('La conversion des notifications a été refusée.');
        }
    }

    private function assertSchema(Connection $connection, string $type): void
    {
        if ($connection->getTablePrefix() !== '') {
            throw new RuntimeException('Schéma refusé.');
        }

        $schema = $connection->selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM pg_catalog.pg_class AS c
                JOIN pg_catalog.pg_namespace AS n ON n.oid = c.relnamespace
                JOIN pg_catalog.pg_attribute AS a ON a.attrelid = c.oid
                WHERE n.nspname = 'public' AND c.relname = 'notifications'
                  AND c.relkind = 'r' AND NOT c.relispartition
                  AND NOT c.relrowsecurity AND NOT c.relforcerowsecurity
                  AND c.oid = pg_catalog.to_regclass('notifications')
                  AND a.attname = 'data' AND a.attnum > 0 AND NOT a.attisdropped
                  AND a.atttypid = CAST(? AS pg_catalog.regtype)
                  AND a.atttypmod = -1 AND a.attnotnull AND NOT a.atthasdef
                  AND a.attgenerated = '' AND a.attidentity = ''
                  AND NOT EXISTS (
                      SELECT 1 FROM pg_catalog.pg_attrdef AS d
                      WHERE d.adrelid = c.oid AND d.adnum = a.attnum
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM pg_catalog.pg_inherits AS i
                      WHERE i.inhrelid = c.oid OR i.inhparent = c.oid
                  )
            ) AS expected
            SQL, ['pg_catalog.'.$type]);

        if (! $this->isTrue($schema?->expected)) {
            throw new RuntimeException('Schéma refusé.');
        }
    }

    private function assertJsonInputSupport(Connection $connection): void
    {
        $support = $connection->selectOne(<<<'SQL'
            SELECT pg_catalog.to_regprocedure(
                'pg_catalog.pg_input_is_valid(text,text)'
            ) IS NOT NULL AS available
            SQL);

        if (! $this->isTrue($support?->available)) {
            throw new RuntimeException('Validation indisponible.');
        }

        // Vérifier les erreurs non bloquantes du type json, pas seulement le symbole.
        $probe = $connection->selectOne(<<<'SQL'
            SELECT pg_catalog.pg_input_is_valid('{"probe":true}', 'pg_catalog.json')
                   AND NOT pg_catalog.pg_input_is_valid('{"probe":', 'pg_catalog.json') AS supported
            SQL);

        if (! $this->isTrue($probe?->supported)) {
            throw new RuntimeException('Validation indisponible.');
        }
    }

    private function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }
};
