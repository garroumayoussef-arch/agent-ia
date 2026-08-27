<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "avoir fournisseur" — enregistrement des avoirs reçus des
 * fournisseurs, immuable après création (cf. SupplierCreditNote::booted()),
 * même principe que supplier_invoices (T28) / supplier_invoice_payments
 * (T30).
 *
 * Architecture validée (étude préalable) :
 * - rattaché OBLIGATOIREMENT à une supplier_invoice précise (decision 1),
 *   jamais directement à un purchase_order (qui peut porter plusieurs
 *   factures fournisseur — décision 2/3 de l'étude) ;
 * - traité au MONTANT GLOBAL de la facture créditée (décision 2) :
 *   aucune ligne produit ici, symétrique de l'absence de lignes sur
 *   supplier_invoices elle-même (contrairement à credit_notes/
 *   credit_note_lines, qui existent parce qu'invoices/invoice_lines
 *   existent) ;
 * - supplier_credit_note_number est le numéro DU FOURNISSEUR, texte
 *   libre — jamais généré par Magarrou (pas de séquence, décision 6),
 *   même logique que supplier_invoices.supplier_invoice_number ;
 * - reason est OPTIONNEL (décision validée) : simple aide à la
 *   traçabilité, jamais bloquant, même statut que notes.
 *
 * restrictOnDelete() sur supplier_invoice_id : même défense en
 * profondeur qu'ailleurs (supplier_invoice_payments, credit_notes) —
 * bien que SupplierInvoice interdise déjà toute suppression
 * (SupplierInvoice::deleting(), T28), la contrainte base garantit
 * qu'un avoir ne peut structurellement jamais référencer une facture
 * inexistante.
 *
 * Retour physique (décrément de stock) explicitement HORS PÉRIMÈTRE :
 * aucune colonne produit/variante/quantité ici, aucune relation vers
 * stock_movements — cette table reste strictement la partie comptable
 * (décision 5). Un futur chantier séparé rattachera le retour physique
 * à purchase_order_items (donnée produit/quantité fiable), jamais à
 * cette table (décision 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();

            // Numéro DU FOURNISSEUR, texte libre — jamais généré par
            // Magarrou (pas de séquence, contrairement à
            // credit_notes.number).
            $table->string('supplier_credit_note_number');
            $table->date('credit_note_date');

            $table->decimal('total_ht', 10, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->decimal('total_ttc', 10, 2);

            // Optionnel (décision validée) : simple aide à la
            // traçabilité, jamais bloquant — même statut que `notes`.
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_notes');
    }
};
