<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T24 (point 5) — compteur de numérotation légale des avoirs,
 * une ligne par année civile. STRICTEMENT séparée d'invoice_sequences
 * (T23, non modifiée) : deux documents légaux distincts, deux
 * séquences indépendantes. Même mécanisme de verrouillage que T23
 * (CreditNoteSequence::nextNumber() — insertOrIgnore() + lockForUpdate()),
 * déjà démontré sans doublon sous concurrence réelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_sequences');
    }
};
