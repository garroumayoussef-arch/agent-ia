<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T23 — identité légale de Magarrou ET régime fiscal, configurés
 * par l'admin, jamais supposés ni codés en dur (D5). Table "singleton"
 * (une seule ligne, id=1, cf. CompanySettings::current()) — aucune
 * contrainte SQL d'unicité n'est nécessaire : c'est
 * CompanySettings::current() qui garantit qu'une seule ligne est
 * jamais lue/créée, pas le schéma.
 *
 * vat_exemption_mention / payment_terms_text / discount_terms_text /
 * late_penalty_text sont des champs TEXTE LIBRES, jamais une valeur
 * calculée par le code : la formulation légale exacte reste de la
 * responsabilité de l'entreprise (D5 — ne jamais hardcoder une mention
 * fiscale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();

            $table->string('legal_name')->nullable();
            $table->string('legal_form')->nullable();
            $table->decimal('share_capital', 12, 2)->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('siren')->nullable();
            $table->string('siret')->nullable();
            $table->string('rcs_city')->nullable();

            // Régime TVA — jamais supposé, jamais de valeur par défaut
            // autre que NULL (D5) : tant que ce champ n'est pas
            // renseigné explicitement, CompanySettings::assertReadyForInvoicing()
            // refuse toute génération de facture.
            $table->string('vat_regime')->nullable();
            $table->string('vat_number')->nullable();
            $table->text('vat_exemption_mention')->nullable();

            // Préparation facturation électronique (option TVA sur les
            // débits, cf. périmètre T23) — inerte tant que le catalogue
            // ne porte que des ventes de biens, jamais affirmée par
            // défaut.
            $table->string('vat_payment_option')->nullable();

            $table->unsignedInteger('default_payment_terms_days')->nullable();
            $table->text('payment_terms_text')->nullable();
            $table->text('discount_terms_text')->nullable();
            $table->text('late_penalty_text')->nullable();
            $table->decimal('recovery_indemnity_amount', 8, 2)->default(40.00);

            $table->string('iban')->nullable();
            $table->string('bic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->string('invoice_number_prefix')->default('FA');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
