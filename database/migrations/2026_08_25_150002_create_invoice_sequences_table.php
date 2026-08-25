<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T23 (D6) — compteur de numérotation légale, une ligne par
 * année civile. Verrouillée en transaction par InvoiceSequence::nextNumber()
 * (lockForUpdate()) pour garantir l'absence de doublon même en cas de
 * générations concurrentes — même principe déjà utilisé dans ce projet
 * par StockTransfer::execute() (verrouillage déterministe des lignes
 * warehouse_stocks concernées).
 *
 * Contrainte unique sur `year` : filet de sécurité supplémentaire côté
 * base contre une double création de la ligne d'une même année (cf.
 * insertOrIgnore() dans InvoiceSequence::nextNumber()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
