<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T12 — lie un StockMovement au StockTransfer qui l'a généré
 * (mouvement 'transfer_out' ou 'transfer_in'). Colonne purement
 * additive et nullable : un mouvement "normal" (achat, vente...) n'a
 * jamais de stock_transfer_id.
 *
 * restrictOnDelete() plutôt que nullOnDelete() (contrairement à
 * purchase_order_id/sales_order_id) : un StockTransfer ne doit jamais
 * pouvoir être supprimé tant que ses mouvements existent — cohérent
 * avec la garde d'indivisibilité posée sur StockMovement (aucune
 * suppression individuelle d'une jambe d'un transfert), qui empêche
 * déjà structurellement que ces mouvements disparaissent seuls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_transfer_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('stock_transfers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_transfer_id');
        });
    }
};
