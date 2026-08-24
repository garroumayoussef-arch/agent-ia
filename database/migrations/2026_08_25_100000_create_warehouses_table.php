<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Étape T10 (fondation multi-entrepôts) — strictement additive :
     * cette table est isolée, sans aucune colonne ajoutée sur
     * products/product_variants/stock_movements, et rien dans
     * l'application actuelle ne la lit ou ne l'écrit. Aucun risque de
     * régression possible sur le stock existant par construction.
     *
     * - code : unique, identifiant court d'affichage/référence.
     * - is_active : même convention que Driver/Vehicle/Customer/TaxRate.
     * - is_default : entrepôt de repli pour la future bascule du champ
     *   texte libre Product.warehouse/ProductVariant.warehouse (T11+) —
     *   un seul actif à la fois, garanti par Warehouse::booted()
     *   (contrainte non exprimable proprement en SQL portable seul).
     *
     * warehouse_stocks (T11) et stock_transfers (T12) ne sont volontairement
     * pas créées ici.
     */
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code')->unique();
            $table->string('address')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
