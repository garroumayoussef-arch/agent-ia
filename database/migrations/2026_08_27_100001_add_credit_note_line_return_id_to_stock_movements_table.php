<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "retour physique" — lie un StockMovement de type 'return' au
 * CreditNoteLineReturn qui l'a généré. Colonne purement additive et
 * nullable : un mouvement "normal" (achat, vente, transfert...) n'a
 * jamais de credit_note_line_return_id — même principe que
 * stock_transfer_id (T12).
 *
 * restrictOnDelete() plutôt que nullOnDelete() (contrairement à
 * sales_order_id/purchase_order_id) : un CreditNoteLineReturn est
 * immuable (cf. CreditNoteLineReturn::booted()) et ne doit jamais
 * pouvoir disparaître tant que le mouvement de stock qu'il a généré
 * existe — même raisonnement exact que stock_transfer_id.
 *
 * Créé UNIQUEMENT pour un retour 'vendable' (cf.
 * CreditNoteLineReturn::recordFor()) : un retour 'defectueux' ne génère
 * jamais de StockMovement, donc jamais de valeur ici pour ce cas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('credit_note_line_return_id')
                ->nullable()
                ->after('stock_transfer_id')
                ->constrained('credit_note_line_returns')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_note_line_return_id');
        });
    }
};
