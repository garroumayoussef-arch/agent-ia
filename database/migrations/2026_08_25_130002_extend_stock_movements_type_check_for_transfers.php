<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Étape T12 — élargit la liste des valeurs acceptées par
 * stock_movements.type pour y ajouter 'transfer_out'/'transfer_in'.
 *
 * Conditionnée au driver PostgreSQL UNIQUEMENT :
 * - Sur PostgreSQL (production, vérifié via le driver installé dans
 *   le Dockerfile et l'historique Git du projet), $table->enum(...)
 *   ne crée PAS un vrai type ENUM natif mais un varchar(255) avec une
 *   contrainte CHECK (vérifié dans le code source de Laravel,
 *   PostgresGrammar::typeEnum()). Cette contrainte doit être élargie
 *   explicitement, sinon toute insertion avec type = 'transfer_out'/
 *   'transfer_in' serait rejetée par la base malgré une validation
 *   applicative correcte (StockMovement::assertValidType()).
 * - Sur SQLite (dev), la colonne `type` est un varchar SANS aucune
 *   contrainte (vérifié directement sur la base de dev) : cette
 *   migration n'y a donc rien à faire, la validation applicative
 *   suffit.
 *
 * La colonne reste un VARCHAR(255) — aucun changement de type SQL,
 * uniquement la contrainte CHECK qui restreint ses valeurs.
 *
 * Recherche dynamique du nom de la contrainte plutôt qu'un nom
 * supposé (ex. stock_movements_type_check) : cette migration n'a pas
 * pu être exécutée ni vérifiée sur un PostgreSQL réel depuis cet
 * environnement de développement (SQLite) — voir le rapport
 * d'implémentation pour ce point.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropExistingTypeCheckConstraintIfAny();

        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check '
            . "CHECK (type IN ('purchase','sale','return','adjustment','transfer','transfer_out','transfer_in','inventory'))"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropExistingTypeCheckConstraintIfAny();

        // Restaure exactement la liste d'origine (celle générée par
        // $table->enum('type', [...]) dans la migration de création).
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check '
            . "CHECK (type IN ('purchase','sale','return','adjustment','transfer','inventory'))"
        );
    }

    /**
     * Retrouve dynamiquement le nom réel de la contrainte CHECK
     * portant sur la colonne `type` de stock_movements (via le
     * catalogue système pg_constraint) et la supprime si elle existe,
     * plutôt que de supposer un nom fixe qui n'a pas pu être vérifié
     * sur une base PostgreSQL réelle depuis cet environnement.
     */
    private function dropExistingTypeCheckConstraintIfAny(): void
    {
        $constraint = DB::selectOne(<<<'SQL'
            select conname
            from pg_constraint
            where conrelid = 'stock_movements'::regclass
              and contype = 'c'
              and pg_get_constraintdef(oid) ilike '%type%in%'
            limit 1
        SQL);

        if ($constraint) {
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT "' . $constraint->conname . '"');
        }
    }
};
