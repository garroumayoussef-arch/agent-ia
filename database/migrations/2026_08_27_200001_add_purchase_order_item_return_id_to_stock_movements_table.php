<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "retour physique fournisseur" — lie un StockMovement de type
 * 'return_to_supplier' au PurchaseOrderItemReturn qui l'a généré.
 * Colonne purement additive et nullable : un mouvement "normal" (achat,
 * vente, transfert, retour client...) n'a jamais de
 * purchase_order_item_return_id — même principe que
 * stock_transfer_id/credit_note_line_return_id.
 *
 * restrictOnDelete() plutôt que nullOnDelete() : un PurchaseOrderItemReturn
 * est immuable (cf. PurchaseOrderItemReturn::booted()) et ne doit jamais
 * pouvoir disparaître tant que le mouvement de stock qu'il a généré
 * existe — même raisonnement exact que credit_note_line_return_id.
 *
 * Créé pour CHAQUE retour (décision 3, validée) : contrairement à
 * credit_note_line_return_id (uniquement pour un retour 'vendable'), un
 * retour fournisseur génère TOUJOURS un StockMovement, sans exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('purchase_order_item_return_id')
                ->nullable()
                ->after('credit_note_line_return_id')
                ->constrained('purchase_order_item_returns')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_return_id');
        });
    }
};
