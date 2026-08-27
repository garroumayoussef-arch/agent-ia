<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "facturation légale VTC" (D2, validé) — la facturation VTC
 * introduit une SECONDE série de numérotation légale (préfixe dédié,
 * cf. company_settings.vtc_invoice_number_prefix), strictement
 * indépendante de la série vente existante (préfixe 'FA' par défaut,
 * T23, inchangée). InvoiceSequence passe donc d'un compteur par ANNÉE
 * à un compteur par (ANNÉE, PRÉFIXE) : la contrainte unique portait
 * jusqu'ici sur `year` seul, ce qui aurait fait PARTAGER le même
 * compteur entre deux préfixes différents la même année si
 * InvoiceSequence::nextNumber() avait jamais été appelée avec deux
 * préfixes différents pour la même année (jamais exploité jusqu'ici,
 * une seule série ayant existé avant ce chantier) — corrigé ici, sans
 * changer le comportement observable de la série vente existante
 * (toujours 'FA', toujours le même compteur qu'avant pour une série
 * unique).
 *
 * prefix par défaut 'FA' sur les lignes déjà existantes : aucune
 * réinitialisation de compteur pour les factures de vente déjà émises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_sequences', function (Blueprint $table) {
            $table->string('prefix')->default('FA')->after('year');
        });

        Schema::table('invoice_sequences', function (Blueprint $table) {
            $table->dropUnique(['year']);
            $table->unique(['year', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_sequences', function (Blueprint $table) {
            $table->dropUnique(['year', 'prefix']);
            $table->unique(['year']);
            $table->dropColumn('prefix');
        });
    }
};
