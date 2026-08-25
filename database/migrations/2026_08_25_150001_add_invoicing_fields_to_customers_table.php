<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T23 — champs nécessaires pour distinguer un client particulier
 * (B2C) d'un client professionnel (B2B) et porter les données légales
 * de ce dernier (SIREN, n° TVA), absentes de `customers` jusqu'ici
 * (vérifié : aucun de ces champs n'existait). Purement additive et
 * nullable : aucun client existant n'est cassé, tous sont traités
 * comme "particulier" par défaut (comportement métier neutre — jamais
 * "professionnel" par défaut, qui exigerait un SIREN absent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_type')->default('individual')->after('company');
            $table->string('postal_code')->nullable()->after('address');
            $table->string('siren')->nullable()->after('postal_code');
            $table->string('vat_number')->nullable()->after('siren');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['customer_type', 'postal_code', 'siren', 'vat_number']);
        });
    }
};
