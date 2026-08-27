<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "facturation légale VTC" (D2, validé) — préfixe de la série
 * de numérotation dédiée aux factures VTC, configurable par l'admin
 * (CompanySettingsPage), jamais codé en dur — même principe que
 * invoice_number_prefix (T23)/credit_note_number_prefix (T24).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('vtc_invoice_number_prefix')->default('FV')->after('invoice_number_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('vtc_invoice_number_prefix');
        });
    }
};
