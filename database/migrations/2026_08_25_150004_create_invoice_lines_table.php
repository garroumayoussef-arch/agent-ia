<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T23 — lignes de facture, snapshot des SalesOrderItem au moment
 * de l'émission (jamais une lecture live de Product/ProductVariant —
 * même principe d'immuabilité que la table invoices).
 *
 * cascadeOnDelete sur invoice_id : une InvoiceLine n'a aucune
 * existence indépendante de sa facture (même convention que
 * sales_order_items -> sales_orders) — sans conséquence pratique
 * puisque Invoice::booted() interdit de toute façon la suppression
 * d'une facture émise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

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
        Schema::dropIfExists('invoice_lines');
    }
};
