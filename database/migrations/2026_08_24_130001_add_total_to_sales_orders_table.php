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
     * Colonne purement additive et nullable : les commandes existantes
     * ne sont pas cassées. On backfille immédiatement les commandes
     * déjà présentes avec la somme des subtotal de leurs lignes — mais
     * seulement si AUCUNE de ces lignes n'a un subtotal NULL,
     * exactement la même règle que celle appliquée par
     * SalesOrder::recalculateTotal() : un total partiellement inconnu
     * doit rester NULL plutôt que de donner une fausse impression de
     * complétude.
     */
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('total', 10, 2)
                ->nullable()
                ->after('notes');
        });

        $orderIds = DB::table('sales_order_items')
            ->distinct()
            ->pluck('sales_order_id');

        foreach ($orderIds as $orderId) {
            $subtotals = DB::table('sales_order_items')
                ->where('sales_order_id', $orderId)
                ->pluck('subtotal');

            if ($subtotals->contains(null)) {
                continue;
            }

            DB::table('sales_orders')
                ->where('id', $orderId)
                ->update(['total' => $subtotals->sum()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('total');
        });
    }
};
