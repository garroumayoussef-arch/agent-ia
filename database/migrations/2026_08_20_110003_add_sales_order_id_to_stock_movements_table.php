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
     * mouvement de stock généré par l'expédition d'une commande de
     * vente pointe vers celle-ci. Miroir de purchase_order_id.
     * N'affecte aucune logique existante de StockMovement.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('sales_order_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('sales_orders')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
    }
};
