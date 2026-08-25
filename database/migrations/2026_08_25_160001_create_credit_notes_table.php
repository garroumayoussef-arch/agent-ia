<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T24 — avoir/note de crédit, immuable après émission (cf.
 * CreditNote::booted()). Rattaché OBLIGATOIREMENT à une Invoice
 * (invoice_id NOT NULL, restrictOnDelete — cohérent avec l'immuabilité
 * déjà garantie sur invoices/invoice_lines par T23).
 *
 * Blocs vendeur/acheteur COPIÉS depuis Invoice au moment de l'émission
 * (jamais recalculés depuis CompanySettings/Customer, même s'ils ont
 * changé depuis) : une seule source de vérité pour ces données —
 * l'Invoice déjà figée — jamais une seconde lecture indépendante.
 *
 * Pas de colonne discount_amount (contrairement à invoices) : la
 * remise de commande est déjà répercutée dans le tax_amount de chaque
 * InvoiceLine (allocation au prorata, T23) — sommer les lignes
 * créditées suffit, sans double calcul de remise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->restrictOnDelete();

            $table->string('number')->unique();
            $table->date('issued_at');
            $table->string('scope'); // 'total' | 'partial'
            $table->text('reason');
            $table->string('settlement_type'); // 'refund' | 'future_invoice' — informatif uniquement (V1)

            // --- Snapshot vendeur (copié depuis Invoice) ---
            $table->string('seller_legal_name');
            $table->string('seller_legal_form')->nullable();
            $table->decimal('seller_share_capital', 12, 2)->nullable();
            $table->string('seller_address');
            $table->string('seller_postal_code');
            $table->string('seller_city');
            $table->string('seller_country');
            $table->string('seller_siren');
            $table->string('seller_siret')->nullable();
            $table->string('seller_rcs_city')->nullable();
            $table->string('seller_vat_number')->nullable();

            // --- Snapshot acheteur (copié depuis Invoice) ---
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('customer_type');
            $table->string('customer_name');
            $table->string('customer_company')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('customer_postal_code')->nullable();
            $table->string('customer_city')->nullable();
            $table->string('customer_country')->nullable();
            $table->string('customer_siren')->nullable();
            $table->string('customer_vat_number')->nullable();

            // --- Traçabilité (dénormalisée depuis Invoice) ---
            $table->string('invoice_number_reference');
            $table->date('invoice_issued_at_reference');
            $table->string('sales_order_reference');

            // --- Montants (somme des lignes créditées par CET avoir) ---
            $table->decimal('total_ht', 10, 2);
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('total_ttc', 10, 2)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('issued');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
