<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Table de référence configurable pour la TVA : aucun taux n'est
     * jamais codé en dur dans PurchaseOrder/SalesOrder, tout passe par
     * une ligne ici.
     *
     * `type` distingue structurellement deux natures différentes :
     * - 'percentage' : un taux numérique s'applique (`rate` renseigné,
     *   `legal_mention` NULL) ;
     * - 'exempt' : aucune TVA n'est facturée, pour une raison légale
     *   précise (`legal_mention` renseigné, ex. "TVA non applicable —
     *   article 293 B du CGI" pour une franchise en base), `rate` NULL.
     *
     * Cette distinction est ce qui permet de ne JAMAIS confondre un
     * taux à 0 % (type=percentage, rate=0.00) avec un régime "TVA non
     * applicable" (type=exempt) : ce sont deux lignes de nature
     * différente, pas la même valeur numérique.
     *
     * `is_default_purchase` / `is_default_sale` sont volontairement
     * deux drapeaux distincts : le taux de repli peut différer entre
     * achats et ventes.
     */
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();

            $table->string('label');
            $table->string('type');

            $table->decimal('rate', 5, 2)->nullable();
            $table->text('legal_mention')->nullable();

            $table->boolean('is_default_purchase')->default(false);
            $table->boolean('is_default_sale')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
