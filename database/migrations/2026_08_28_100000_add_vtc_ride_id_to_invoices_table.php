<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier "facturation légale VTC" (D1, validé) — une Invoice peut
 * désormais avoir DEUX origines possibles : une SalesOrder (T23,
 * inchangée) OU une VtcRide (nouveau). Exactement une des deux colonnes
 * d'origine est renseignée, jamais les deux, jamais aucune — contrainte
 * vérifiée applicativement dans Invoice::generateFromSalesOrder()/
 * generateFromVtcRide(), jamais en base (les deux colonnes restent
 * nullable indépendamment l'une de l'autre).
 *
 * sales_order_id devient nullable : aucune ligne existante n'est
 * affectée (toutes les factures déjà émises ont une SalesOrder non
 * nulle). sales_order_reference (snapshot texte) devient nullable pour
 * la même raison — une facture VTC renseigne vtc_ride_reference à la
 * place, jamais les deux à la fois.
 *
 * vtc_ride_id : UNIQUE (D5, validé) — rempart final anti-doublon,
 * niveau 4 de la défense en profondeur (UI -> pré-vérification
 * applicative -> re-vérification en transaction -> contrainte UNIQUE),
 * même principe que credit_note_lines.invoice_line_id (T24).
 * restrictOnDelete() : une VtcRide déjà facturée ne peut jamais
 * disparaître silencieusement sous la facture (même garde que
 * sales_order_id ci-dessus, cf. la migration T23 d'origine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->change();
            $table->string('sales_order_reference')->nullable()->change();

            $table->foreignId('vtc_ride_id')
                ->nullable()
                ->unique()
                ->after('sales_order_id')
                ->constrained('vtc_rides')
                ->restrictOnDelete();

            $table->string('vtc_ride_reference')->nullable()->after('sales_order_reference');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vtc_ride_id');
            $table->dropColumn('vtc_ride_reference');

            $table->foreignId('sales_order_id')->nullable(false)->change();
            $table->string('sales_order_reference')->nullable(false)->change();
        });
    }
};
