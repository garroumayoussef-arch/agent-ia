<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * tax_amount = somme exacte des tax_amount (déjà arrondis) des
     * lignes — jamais recalculé indépendamment, pour garantir que la
     * commande reste toujours réconciliable avec ses lignes.
     *
     * total_ttc = total (HT après remise, sémantique inchangée depuis
     * l'étape 2/3) + tax_amount.
     *
     * Colonnes additives et nullables : aucune commande existante
     * n'est cassée.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('tax_amount', 10, 2)->nullable()->after('total');
            $table->decimal('total_ttc', 10, 2)->nullable()->after('tax_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['tax_amount', 'total_ttc']);
        });
    }
};
