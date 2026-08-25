<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T12 — table d'historique/intention d'un transfert de stock
 * entre deux entrepôts. C'est un document, pas le mécanisme
 * d'exécution lui-même : l'effet réel sur le stock passe par les deux
 * StockMovement (transfer_out / transfer_in) créés par
 * StockTransfer::execute() et liés via stock_movements.stock_transfer_id
 * (migration suivante).
 *
 * from_warehouse_id / to_warehouse_id en restrictOnDelete() : un
 * entrepôt référencé par un historique de transfert ne peut jamais
 * être supprimé — même principe que warehouse_stocks.warehouse_id
 * (T11a) et stock_movements.warehouse_id (T11b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('from_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('to_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();

            $table->integer('quantity');

            $table->string('reference')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
