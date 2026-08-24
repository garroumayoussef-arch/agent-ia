<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Remise en montant fixe (EUR), au niveau de la commande.
     * Contrairement à unit_price/subtotal/total, une remise "non
     * renseignée" a un sens métier non ambigu : aucune remise, donc 0 —
     * la colonne est NOT NULL avec un défaut à 0.00, ce qui backfille
     * automatiquement les commandes existantes sans requête
     * supplémentaire.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)
                ->default(0)
                ->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
