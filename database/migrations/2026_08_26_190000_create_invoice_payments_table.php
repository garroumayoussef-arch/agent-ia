<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T31 — suivi des paiements clients, symétrique de
 * supplier_invoice_payments (T30). Un paiement est immuable dès son
 * enregistrement (cf. InvoicePayment::booted()) : aucune correction/
 * annulation en V1 (décision validée), une facture peut avoir
 * plusieurs paiements (partiels).
 *
 * restrictOnDelete() sur invoice_id : même défense en profondeur
 * qu'ailleurs — bien qu'Invoice interdise déjà toute suppression
 * (Invoice::deleting(), T23), la contrainte base garantit qu'un
 * paiement ne peut structurellement jamais référencer une facture
 * inexistante.
 *
 * Le statut (non payée/partiellement payée/payée) n'est JAMAIS stocké
 * ici ni sur invoices : toujours recalculé à la volée depuis la somme
 * réelle des lignes de cette table (cf. Invoice::paymentStatus()) —
 * aucun champ de statut à désynchroniser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();

            $table->decimal('amount', 10, 2);
            $table->date('paid_at');

            // Optionnelle (décision validée) : simple aide à la
            // traçabilité humaine (ex. numéro de virement), jamais une
            // contrainte d'unicité — le système ne peut pas garantir
            // structurellement l'absence de double saisie sans
            // rapprochement bancaire (hors périmètre V1).
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
