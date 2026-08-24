<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Étape T11a — décomposition du stock par entrepôt. Table encore
     * strictement additive à ce stade : aucune colonne ajoutée sur
     * products/product_variants/stock_movements, et rien dans
     * StockMovement ne l'alimente encore (T11b). Le rétro-remplissage
     * (WarehouseStockSeeder) attribue tout le stock existant à
     * l'entrepôt is_default (créé en T10) — seule représentation
     * possible pour un stock dont l'historique par entrepôt n'a jamais
     * été suivi.
     *
     * - product_id : cascadeOnDelete, miroir exact de
     *   stock_movements.product_id.
     * - product_variant_id : cascadeOnDelete — diverge volontairement
     *   de stock_movements.product_variant_id (nullOnDelete) :
     *   ProductVariant::deleting() protège déjà contre la suppression
     *   d'une variante ayant des stock_movements, mais ignore encore
     *   warehouse_stocks (ProductVariant.php hors périmètre de T11a).
     *   Mettre product_variant_id à NULL en cas de suppression
     *   laisserait une ligne ambiguë, confondue avec une vraie ligne
     *   "produit sans variante" — cascader évite ce risque.
     * - warehouse_id : restrictOnDelete, en complément défensif de la
     *   garde Warehouse::deleting() (ajoutée dans cette même étape).
     *
     * Contrainte d'unicité (warehouse_id, product_id, product_variant_id) :
     * NULL n'étant jamais égal à NULL pour une contrainte SQL, elle ne
     * bloque pas à elle seule deux lignes "produit sans variante" en
     * doublon (product_variant_id IS NULL des deux côtés) — l'idempotence
     * réelle du rétro-remplissage repose sur firstOrCreate() côté
     * application (WarehouseStockSeeder), cette contrainte restant un
     * filet de sécurité pour le cas variante (valeur non NULL).
     */
    public function up(): void
    {
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnDelete();

            $table->integer('stock')->default(0);

            $table->timestamps();

            $table->unique(['warehouse_id', 'product_id', 'product_variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
