<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chantier "retour physique fournisseur" — élargit la liste des valeurs
 * acceptées par stock_movements.type pour y ajouter 'return_to_supplier'.
 *
 * Conditionnée au driver PostgreSQL UNIQUEMENT, même raisonnement exact
 * que 2026_08_25_130002_extend_stock_movements_type_check_for_transfers
 * (T12) : sur SQLite (dev), la colonne `type` est un varchar SANS
 * aucune contrainte, cette migration n'y a donc rien à faire.
 *
 * La colonne reste un VARCHAR(255) — aucun changement de type SQL,
 * uniquement la contrainte CHECK qui restreint ses valeurs.
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
            . "CHECK (type IN ('purchase','sale','return','adjustment','transfer','transfer_out','transfer_in','inventory','return_to_supplier'))"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->dropExistingTypeCheckConstraintIfAny();

        // Restaure exactement la liste précédente (T12), sans
        // 'return_to_supplier'.
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check '
            . "CHECK (type IN ('purchase','sale','return','adjustment','transfer','transfer_out','transfer_in','inventory'))"
        );
    }

    /**
     * Retrouve dynamiquement le nom réel de la contrainte CHECK portant
     * sur la colonne `type` de stock_movements (via le catalogue système
     * pg_constraint) et la supprime si elle existe, plutôt que de
     * supposer un nom fixe — même mécanisme exact que T12.
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
