<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deux colonnes distinctes (et non une seule partagée) : le taux
     * de TVA par défaut d'un produit peut différer à l'achat et à la
     * vente. Colonnes additives et nullables : les produits existants
     * ne sont pas cassés, ils retombent simplement sur le taux par
     * défaut système (is_default_purchase/is_default_sale) tant
     * qu'aucun taux spécifique ne leur est assigné.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('purchase_tax_rate_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('tax_rates')
                ->nullOnDelete();

            $table->foreignId('sale_tax_rate_id')
                ->nullable()
                ->after('purchase_tax_rate_id')
                ->constrained('tax_rates')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['purchase_tax_rate_id']);
            $table->dropForeign(['sale_tax_rate_id']);
            $table->dropColumn(['purchase_tax_rate_id', 'sale_tax_rate_id']);
        });
    }
};
