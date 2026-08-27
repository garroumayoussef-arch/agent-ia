<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "retour physique" (Option 3b, validée) — un retour physique
 * déclaré contre une ligne d'avoir (CreditNoteLine), immuable dès son
 * enregistrement (cf. CreditNoteLineReturn::booted()). Plusieurs
 * retours successifs sont volontairement possibles sur la même ligne
 * (aucune contrainte UNIQUE sur credit_note_line_id), plafonnés en
 * cumul par CreditNoteLineReturn::recordFor() à la quantité créditée
 * de la ligne — jamais au niveau de la base.
 *
 * Règle centrale validée : avoir financier ≠ retour physique ≠
 * mouvement de stock. CreditNote::generateFromInvoice() (T24) ne crée
 * jamais de ligne ici — un avoir sans retour physique déclaré ne
 * modifie jamais le stock.
 *
 * product_id/product_variant_id sont des COPIES FIGÉES (snapshot) de
 * CreditNoteLine.product_id/product_variant_id au moment du retour —
 * même convention que InvoiceLine/CreditNoteLine, qui snapshotent
 * systématiquement leurs données d'origine plutôt que de dépendre
 * d'une jointure vivante. La validation métier (produit/variante
 * obligatoire, vendable ET défectueux) lit CreditNoteLine comme source
 * d'autorité au moment de l'écriture — ces colonnes ne sont qu'une
 * trace d'audit figée, jamais une seconde source de vérité divergente.
 *
 * returned_at est une date MÉTIER (la date réelle du retour physique,
 * modifiable par l'opérateur), distincte de created_at (horodatage
 * système) — même principe que InvoicePayment.paid_at.
 *
 * condition ('vendable' | 'defectueux') pilote la création ou non d'un
 * StockMovement associé (cf. CreditNoteLineReturn::recordFor()) :
 * jamais un DB enum, même convention que credit_notes.scope/
 * settlement_type (chaîne libre commentée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_line_returns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('credit_note_line_id')
                ->constrained('credit_note_lines')
                ->restrictOnDelete();

            // Copies figées depuis CreditNoteLine au moment du retour —
            // jamais la seule source de vérité pour la validation (cf.
            // documentation de tête de migration).
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->integer('quantity');
            $table->string('condition'); // 'vendable' | 'defectueux'
            $table->date('returned_at');

            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_line_returns');
    }
};
