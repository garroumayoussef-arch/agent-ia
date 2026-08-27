<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "retour physique fournisseur" (décisions 1 à 6 validées) —
 * un retour physique de marchandise déclaré contre une PurchaseOrderItem
 * réceptionnée, immuable dès son enregistrement (cf.
 * PurchaseOrderItemReturn::booted()). Plusieurs retours successifs sont
 * volontairement possibles sur la même ligne (aucune contrainte UNIQUE
 * sur purchase_order_item_id), plafonnés en cumul par
 * PurchaseOrderItemReturn::recordFor() à la quantité RÉCEPTIONNÉE de la
 * ligne (quantity_received) — jamais à quantity_ordered, et jamais au
 * niveau de la base.
 *
 * Décision 1 (validée) : AUCUN lien avec SupplierCreditNote — reproduit
 * fidèlement la règle déjà actée côté vente (avoir financier ≠ retour
 * physique ≠ mouvement de stock), ici encore plus stricte puisque
 * SupplierInvoice/SupplierCreditNote sont traités au montant global,
 * sans aucune ligne produit (cf. SupplierCreditNote, en-tête de classe).
 *
 * Décision 2 (validée) : rattachement à PurchaseOrderItem (donnée
 * produit/quantité fiable), jamais à PurchaseOrder ni SupplierInvoice.
 *
 * Décision 3 (validée) : AUCUNE distinction de condition (contrairement
 * à CreditNoteLineReturn.condition) — un retour fournisseur est par
 * définition une expédition physique vers l'extérieur, il n'existe pas
 * de variante "reste en interne sans être expédié". `reason` est un
 * champ libre optionnel, simple aide à la traçabilité, sans effet sur
 * le StockMovement généré (systématique, cf. migration stock_movements).
 *
 * product_id/product_variant_id sont des COPIES FIGÉES (snapshot) de
 * PurchaseOrderItem.product_id/product_variant_id au moment du retour —
 * même convention que credit_note_line_returns. En pratique,
 * PurchaseOrderItem.product_id est NOT NULL et cascadeOnDelete (contrairement
 * à CreditNoteLine) : ce snapshot ne peut donc jamais être NULL par ce
 * chemin (la suppression du produit supprimerait la ligne elle-même en
 * cascade) — nullable ici uniquement par cohérence de convention avec
 * credit_note_line_returns, jamais parce que le cas est atteignable.
 *
 * returned_at est une date MÉTIER (la date réelle du retour physique),
 * distincte de created_at (horodatage système) — même principe que
 * CreditNoteLineReturn.returned_at/InvoicePayment.paid_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_item_returns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_item_id')
                ->constrained('purchase_order_items')
                ->restrictOnDelete();

            // Copies figées depuis PurchaseOrderItem au moment du retour —
            // jamais la seule source de vérité pour la validation (cf.
            // documentation de tête de migration).
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();

            $table->integer('quantity');
            $table->date('returned_at');

            // Décision 3 (validée) : texte libre, optionnel, jamais un
            // enum, aucun effet sur le StockMovement généré.
            $table->text('reason')->nullable();

            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_item_returns');
    }
};
