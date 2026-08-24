<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Une course VTC est une prestation à ligne unique (contrairement
     * à PurchaseOrder/SalesOrder) : pas de table d'items séparée, tout
     * vit sur cette table. Aucune colonne de stock, aucune référence à
     * products/product_variants/stock_movements : une course VTC ne
     * doit jamais entrer dans la logique de stock.
     *
     * - customer_id/driver_id/vehicle_id : nullables en brouillon
     *   (une course peut être préparée avant d'être complétée) — la
     *   logique métier (étape 2) exige driver/vehicle à la confirmation,
     *   mais ce n'est PAS une contrainte de schéma ici.
     * - price_ht/discount_amount/total_ht/tax_rate_id/tax_rate/
     *   tax_amount/total_ttc : même principe de calcul et de gel après
     *   confirmation que PurchaseOrder(Item)/SalesOrder(Item).
     * - tax_status : statut fiscal explicite ('taxable'/'exempt'/
     *   'unresolved'), redondant par construction avec tax_rate_id/
     *   tax_rate mais gardé en clair pour la lisibilité/le filtrage,
     *   sans jamais remplacer la distinction NULL (inconnu) vs 0.00
     *   (connu, nul) sur tax_amount.
     * - legal_mention : mention légale figée au moment de la
     *   confirmation si le taux résolu est du type "exempt" (ex.
     *   franchise en base, article 293 B du CGI).
     */
    public function up(): void
    {
        Schema::create('vtc_rides', function (Blueprint $table) {
            $table->id();

            $table->string('reference')->unique();

            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            $table->foreignId('driver_id')
                ->nullable()
                ->constrained('drivers')
                ->nullOnDelete();

            $table->foreignId('vehicle_id')
                ->nullable()
                ->constrained('vehicles')
                ->nullOnDelete();

            $table->string('platform')->nullable();
            $table->dateTime('performed_at')->nullable();

            $table->string('status')->default('draft');

            $table->decimal('price_ht', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_ht', 10, 2)->nullable();

            $table->foreignId('tax_rate_id')
                ->nullable()
                ->constrained('tax_rates')
                ->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('total_ttc', 10, 2)->nullable();
            $table->string('tax_status')->default('unresolved');
            $table->text('legal_mention')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vtc_rides');
    }
};
