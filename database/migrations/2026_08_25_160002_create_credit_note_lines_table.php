<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T24 — ligne d'avoir, snapshot figé d'une InvoiceLine créditée.
 *
 * Contrainte UNIQUE sur invoice_line_id : c'est elle, et elle seule,
 * qui rend le SUR-CRÉDIT structurellement impossible — une même ligne
 * de facture ne peut jamais être créditée deux fois, tous avoirs
 * confondus, même en cas de contournement applicatif ou d'appel
 * concurrent (barrière autoritaire de dernier recours, en plus du
 * contrôle applicatif de CreditNote::generateFromInvoice()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();

            $table->foreignId('invoice_line_id')
                ->unique()
                ->constrained('invoice_lines')
                ->restrictOnDelete();

            // Purement informatif/traçabilité : jamais utilisé pour lire
            // des données live (product_name/variant_description
            // ci-dessous sont la source d'affichage figée).
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->string('product_name');
            $table->string('variant_description')->nullable();

            $table->integer('quantity');
            $table->decimal('unit_price_ht', 10, 2);
            $table->decimal('subtotal_ht', 10, 2);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('total_ttc', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
    }
};
