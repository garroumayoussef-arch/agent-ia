<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "facturation légale VTC" (D11, validé) — conséquence directe
 * de D1/D4 : credit_notes.sales_order_reference (dénormalisée depuis
 * Invoice au moment de l'émission de l'avoir, T24) était NOT NULL,
 * hérité d'une époque où une Invoice n'avait qu'une seule origine
 * possible. Une facture VTC (D1) a sales_order_reference NULL — sans
 * ce correctif, CreditNote::generateFromInvoice() échouait sur une
 * violation de contrainte NOT NULL en tentant d'émettre un avoir sur
 * une facture VTC (découvert empiriquement pendant ce chantier).
 *
 * vtc_ride_reference : symétrique de invoices.vtc_ride_reference,
 * renseignée uniquement quand l'avoir porte sur une facture VTC —
 * jamais les deux à la fois (même règle XOR que sur invoices, D1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('sales_order_reference')->nullable()->change();
            $table->string('vtc_ride_reference')->nullable()->after('sales_order_reference');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn('vtc_ride_reference');
            $table->string('sales_order_reference')->nullable(false)->change();
        });
    }
};
