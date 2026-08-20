<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Colonne purement additive (nullable) pour la traçabilité : un
     * mouvement de stock généré par la réception d'un bon de commande
     * pointe vers celui-ci. N'affecte aucune logique existante de
     * StockMovement (guarded = [] gère l'assignation automatiquement).
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained('purchase_orders')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn('purchase_order_id');
        });
    }
};
