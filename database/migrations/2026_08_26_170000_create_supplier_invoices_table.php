<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T28 — enregistrement des factures fournisseurs reçues,
 * immuable après création (cf. SupplierInvoice::booted()) — même
 * principe que la table `invoices` (T23), mais sans numérotation
 * (numéro DU FOURNISSEUR, texte libre, jamais généré par Magarrou) ni
 * snapshot légal (Magarrou n'émet rien ici, elle enregistre un
 * document tiers).
 *
 * restrictOnDelete() sur supplier_id/purchase_order_id : même défense
 * en profondeur qu'ailleurs dans ce projet (invoices, stock_movements)
 * — un enregistrement comptable ne doit jamais disparaître
 * silencieusement sous la suppression de son fournisseur/bon de
 * commande.
 *
 * Pas de colonne de pièce jointe en V1 (décision explicite) : une
 * future migration additive (ex. `attachment_path` nullable)
 * suffirait, sans toucher aux colonnes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();

            // Numéro DU FOURNISSEUR, texte libre — jamais généré par
            // Magarrou (pas de séquence, contrairement à
            // invoices.number/credit_notes.number).
            $table->string('supplier_invoice_number');
            $table->date('invoice_date');

            $table->decimal('total_ht', 10, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->decimal('total_ttc', 10, 2);

            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};
