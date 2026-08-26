<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T30 — suivi des paiements fournisseurs. Un paiement est
 * immuable dès son enregistrement (cf. SupplierInvoicePayment::booted()),
 * même principe que supplier_invoices (T28) : aucune correction/
 * annulation en V1 (décision validée), une facture peut avoir
 * plusieurs paiements (partiels).
 *
 * restrictOnDelete() sur supplier_invoice_id : même défense en
 * profondeur qu'ailleurs (invoices, supplier_invoices elles-mêmes) —
 * bien que SupplierInvoice interdise déjà toute suppression
 * (SupplierInvoice::deleting(), T28), la contrainte base garantit
 * qu'un paiement ne peut structurellement jamais référencer une
 * facture inexistante.
 *
 * Le statut (non payée/partiellement payée/payée) n'est JAMAIS stocké
 * ici ni sur supplier_invoices : toujours recalculé à la volée depuis
 * la somme réelle des lignes de cette table (cf.
 * SupplierInvoice::paymentStatus()) — aucun champ de statut à
 * désynchroniser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoice_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();

            $table->decimal('amount', 10, 2);
            $table->date('paid_at');

            // Optionnelle (décision 2) : simple aide à la traçabilité
            // humaine (ex. numéro de virement), jamais une contrainte
            // d'unicité — le système ne peut pas garantir
            // structurellement l'absence de double saisie sans
            // rapprochement bancaire (explicitement hors périmètre V1).
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_payments');
    }
};
