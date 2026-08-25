<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T11b — branche stock_movements sur la dimension entrepôt
 * introduite en T10/T11a. Colonne nullable à ce stade transitoire :
 * StockMovement::creating() résout systématiquement un warehouse_id
 * (entrepôt par défaut en repli) avant insertion, donc aucune ligne
 * NOUVELLE n'est jamais créée avec warehouse_id = NULL. Les lignes
 * HISTORIQUES restent NULL après cette seule migration de schéma :
 * leur rattachement à l'entrepôt par défaut est fait séparément par
 * StockMovementWarehouseSeeder (réconciliation, idempotent), jamais
 * par du DML dans une migration.
 *
 * restrictOnDelete() : un entrepôt référencé par de l'historique de
 * mouvements ne peut jamais être supprimé (même principe que
 * warehouse_stocks.warehouse_id en T11a).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained('warehouses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });
    }
};
