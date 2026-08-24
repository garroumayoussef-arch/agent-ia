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
     * Colonne purement additive et nullable : les bons de commande
     * existants ne sont pas cassés. On backfille immédiatement les
     * bons déjà présents avec la somme des subtotal de leurs lignes —
     * mais seulement si AUCUNE de ces lignes n'a un subtotal NULL,
     * exactement la même règle que celle appliquée par
     * PurchaseOrder::recalculateTotal() : un total partiellement
     * inconnu doit rester NULL plutôt que de donner une fausse
     * impression de complétude.
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('total', 10, 2)
                ->nullable()
                ->after('notes');
        });

        $orderIds = DB::table('purchase_order_items')
            ->distinct()
            ->pluck('purchase_order_id');

        foreach ($orderIds as $orderId) {
            $subtotals = DB::table('purchase_order_items')
                ->where('purchase_order_id', $orderId)
                ->pluck('subtotal');

            if ($subtotals->contains(null)) {
                continue;
            }

            DB::table('purchase_orders')
                ->where('id', $orderId)
                ->update(['total' => $subtotals->sum()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('total');
        });
    }
};
