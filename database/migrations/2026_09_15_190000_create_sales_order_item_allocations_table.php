<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier Dropshipping, étape D2.4.7 — première capacité d'ÉCRITURE du
 * chantier : persistance d'un instantané de la décision de sourcing
 * fournisseur pour une ligne de commande client (SalesOrderItem). Table
 * purement additive : aucune colonne ajoutée à une table existante,
 * aucun changement du schéma D1 (supplier_product_sourcing intact).
 *
 * - sales_order_item_id : restrictOnDelete + UNIQUE — une seule
 *   allocation par ligne dans cette étape (split multi-fournisseur hors
 *   périmètre) ; une ligne ayant une décision historique rattachée ne
 *   peut pas disparaître silencieusement, même convention que
 *   purchase_order_item_returns.purchase_order_item_id.
 * - supplier_product_sourcing_id : restrictOnDelete — une configuration
 *   de sourcing déjà référencée par une décision réelle ne peut pas être
 *   supprimée silencieusement, même convention que
 *   supplier_product_sourcing.supplier_id (D1).
 *
 * Aucune colonne product_id/product_variant_id snapshotée séparément :
 * sales_order_item_id + supplier_product_sourcing_id suffisent à
 * retrouver le produit/variante concerné sans duplication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_item_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_item_id')
                ->unique()
                ->constrained('sales_order_items')
                ->restrictOnDelete();

            $table->foreignId('supplier_product_sourcing_id')
                ->constrained('supplier_product_sourcing')
                ->restrictOnDelete();

            $table->integer('quantity');

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_item_allocations');
    }
};
