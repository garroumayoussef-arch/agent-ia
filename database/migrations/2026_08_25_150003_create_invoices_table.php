<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape T23 — facture légale française, immuable après émission (D5
 * dans l'analyse précédente / cf. Invoice::booted()). Toutes les
 * données vendeur/acheteur/régime fiscal sont SNAPSHOTÉES ici au
 * moment de l'émission (jamais relues depuis Customer/CompanySettings
 * après coup) : une facture doit rester historiquement exacte même si
 * le client déménage ou si Magarrou change de régime de TVA plus tard.
 *
 * restrictOnDelete() partout : une SalesOrder/Customer/Product ne
 * doit jamais pouvoir disparaître silencieusement sous une facture
 * déjà émise (défense en profondeur, même principe que
 * warehouse_stocks/stock_transfers dans ce projet) — contrairement à
 * ces derniers cependant, aucune protection applicative miroir n'est
 * nécessaire côté SalesOrder/Customer : ces modèles n'ont pas de
 * garde deleting() nouvelle à ajouter ici, restrictOnDelete() suffit
 * déjà à bloquer la suppression en base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_order_id')
                ->constrained('sales_orders')
                ->restrictOnDelete();

            $table->string('number')->unique();
            $table->date('issued_at');

            // Date de vente/prestation (D3) — dernière expédition liée à
            // la commande (stock_movements.sales_order_id), donnée déjà
            // fiable et existante, jamais une saisie arbitraire.
            $table->date('sale_completed_at');

            // Préparation facturation électronique (catégorie
            // d'opération, cf. périmètre T23) — toujours 'vente' en V1
            // (catalogue = uniquement des Product, jamais une
            // prestation), le champ existe pour ne pas restructurer
            // plus tard.
            $table->string('operation_category')->default('vente');

            // Classification informative pour un futur e-reporting —
            // n'affecte aucune mention légale affichée sur le PDF
            // (celles-ci dépendent uniquement du régime TVA snapshoté
            // ci-dessous).
            $table->string('transaction_type');

            $table->string('sales_order_reference');

            // --- Snapshot client (jamais relu depuis Customer après coup) ---
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('customer_type');
            $table->string('customer_name');
            $table->string('customer_company')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('customer_postal_code')->nullable();
            $table->string('customer_city')->nullable();
            $table->string('customer_country')->nullable();
            $table->string('customer_siren')->nullable();
            $table->string('customer_vat_number')->nullable();
            $table->text('delivery_address_snapshot')->nullable();

            // --- Snapshot vendeur (Magarrou, jamais relu depuis CompanySettings après coup) ---
            $table->string('seller_legal_name');
            $table->string('seller_legal_form')->nullable();
            $table->decimal('seller_share_capital', 12, 2)->nullable();
            $table->string('seller_address');
            $table->string('seller_postal_code');
            $table->string('seller_city');
            $table->string('seller_country');
            $table->string('seller_siren');
            $table->string('seller_siret')->nullable();
            $table->string('seller_rcs_city')->nullable();
            $table->string('seller_vat_number')->nullable();

            // --- Snapshot régime fiscal (D5 — jamais supposé, copié tel quel) ---
            $table->string('vat_regime_snapshot');
            $table->text('vat_exemption_mention_snapshot')->nullable();
            $table->string('vat_payment_option_snapshot')->nullable();

            // --- Snapshot mentions commerciales ---
            $table->text('payment_terms_snapshot')->nullable();
            $table->text('discount_terms_snapshot')->nullable();
            $table->text('late_penalty_snapshot')->nullable();
            $table->decimal('recovery_indemnity_amount_snapshot', 8, 2);

            // --- Montants (copiés depuis SalesOrder, jamais recalculés) ---
            $table->decimal('total_ht', 10, 2);
            $table->decimal('discount_amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('total_ttc', 10, 2)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('issued');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
