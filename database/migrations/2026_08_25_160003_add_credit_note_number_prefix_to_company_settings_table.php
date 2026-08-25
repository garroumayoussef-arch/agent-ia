<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T24 (point 5) — préfixe configurable de numérotation des
 * avoirs, en miroir d'invoice_number_prefix (T23). Colonne additive
 * avec défaut, aucune ligne existante cassée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('credit_note_number_prefix')->default('AV')->after('invoice_number_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('credit_note_number_prefix');
        });
    }
};
