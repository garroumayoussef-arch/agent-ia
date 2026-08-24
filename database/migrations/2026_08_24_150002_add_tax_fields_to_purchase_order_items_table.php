<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - tax_rate_id / tax_rate : quel taux a été résolu pour cette
     *   ligne, et sa valeur numérique figée au moment approprié (même
     *   principe que unit_price/subtotal) — une modification future du
     *   taux par défaut d'un produit ne doit jamais changer une
     *   commande déjà confirmée.
     * - gross_tax_amount : TVA théorique sur le subtotal SANS tenir
     *   compte de la remise de commande. Sert uniquement de valeur
     *   intermédiaire/traçabilité, jamais affiché comme "la" TVA de la
     *   ligne.
     * - tax_amount : TVA réellement applicable à la ligne APRÈS
     *   allocation au prorata de la remise de commande — c'est ce
     *   champ, et lui seul, qui doit se réconcilier avec la somme des
     *   tax_amount au niveau du bon de commande.
     *
     * Toutes nullables : une ligne sans taux résolu (aucun taux
     * configuré nulle part) garde ces champs à NULL plutôt que de
     * supposer une valeur.
     */
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')
                ->nullable()
                ->after('subtotal')
                ->constrained('tax_rates')
                ->nullOnDelete();

            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_rate_id');
            $table->decimal('gross_tax_amount', 10, 2)->nullable()->after('tax_rate');
            $table->decimal('tax_amount', 10, 2)->nullable()->after('gross_tax_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['tax_rate_id']);
            $table->dropColumn(['tax_rate_id', 'tax_rate', 'gross_tax_amount', 'tax_amount']);
        });
    }
};
