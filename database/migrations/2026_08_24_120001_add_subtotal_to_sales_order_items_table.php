<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Colonne purement additive et nullable : les lignes existantes ne
     * sont pas cassées. On backfille immédiatement les lignes déjà
     * présentes avec quantity_ordered * unit_price quand unit_price est
     * renseigné (même formule que le calcul automatique désormais
     * appliqué par SalesOrderItem) ; sinon subtotal reste NULL, aucune
     * règle de prix par défaut n'étant validée pour ce cas.
     */
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('subtotal', 10, 2)
                ->nullable()
                ->after('unit_price');
        });

        DB::table('sales_order_items')
            ->whereNotNull('unit_price')
            ->update([
                'subtotal' => DB::raw('quantity_ordered * unit_price'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn('subtotal');
        });
    }
};
