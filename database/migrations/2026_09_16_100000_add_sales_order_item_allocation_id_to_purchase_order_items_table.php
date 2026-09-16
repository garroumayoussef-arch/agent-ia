<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier Dropshipping, étape D2.6.2 — colonne de traçabilité entre une
 * ligne de commande fournisseur (PurchaseOrderItem) et la décision de
 * sourcing qui l'a produite (SalesOrderItemAllocation, D2.4.7). Purement
 * additive : aucune donnée existante n'est lue, transformée ni
 * recalculée — chaque ligne déjà présente reçoit simplement NULL pour
 * cette nouvelle colonne.
 *
 * - nullable : les PurchaseOrderItem créés manuellement (flux existant,
 *   totalement inchangé) n'ont et n'auront jamais de valeur ici.
 * - unique : garantit au niveau base - jamais seulement applicatif -
 *   qu'une même allocation ne peut jamais être référencée par deux
 *   PurchaseOrderItem (règle métier validée : une SalesOrderItemAllocation
 *   ne peut être reliée qu'à une seule PurchaseOrderItem). Aucun index
 *   partiel nécessaire : une contrainte UNIQUE sur une colonne nullable
 *   tolère nativement plusieurs NULL, sur SQLite comme sur PostgreSQL
 *   (NULL n'est jamais égal à NULL en SQL standard) - contrairement au
 *   cas de supplier_product_sourcing (D1), une seule colonne est ici en
 *   jeu, pas une combinaison.
 * - restrictOnDelete : une allocation déjà convertie en ligne d'achat ne
 *   peut pas être supprimée silencieusement - même philosophie que
 *   supplier_product_sourcing.supplier_id (D1), pour ne jamais faire
 *   disparaître une décision métier encore référencée.
 *
 * Aucun changement de PurchaseOrder.php (markAsOrdered()/receive()/
 * cancel()), de SalesOrder.php, ni de StockMovement.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('sales_order_item_allocation_id')
                ->nullable()
                ->unique()
                ->after('product_variant_id')
                ->constrained('sales_order_item_allocations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_item_allocation_id');
        });
    }
};
