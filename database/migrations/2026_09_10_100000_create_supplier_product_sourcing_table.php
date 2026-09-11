<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier Dropshipping, étape D1 — fondation du sourcing fournisseur :
 * "quel fournisseur peut sourcer quel produit/variante, à quel coût
 * déclaratif, avec quelle priorité, quel délai". Table Core, purement
 * additive et isolée : aucune colonne ajoutée à une table existante,
 * aucune interaction avec sales_orders/purchase_orders/stock_movements
 * à ce stade (allocation, expédition, orchestration automatique :
 * étapes ultérieures D2+, hors périmètre ici).
 *
 * Volontairement neutre vis-à-vis des activités : aucune colonne
 * `activity`, aucune référence au mot "dropshipping" dans le schéma —
 * un produit Sport/Bébé/Moto/Artisanat peut avoir une fiche de sourcing
 * exactement de la même façon qu'un produit de l'activité Dropshipping
 * (orientation validée : capacité réutilisable du Core, jamais attachée
 * exclusivement à une activité).
 *
 * - product_id : cascadeOnDelete, même convention que
 *   stock_movements.product_id.
 * - product_variant_id : cascadeOnDelete, même convention que
 *   warehouse_stocks.product_variant_id.
 *   Contrairement à purchase_order_items/stock_movements (historique
 *   financier/audit, jamais cascadé), supplier_product_sourcing est une
 *   pure donnée de CONFIGURATION : sa disparition silencieuse avec le
 *   produit/la variante ne fait perdre aucun historique.
 * - supplier_id : restrictOnDelete (décision validée) — un fournisseur
 *   ayant au moins une fiche de sourcing (active ou non) ne peut pas
 *   être supprimé, pour ne jamais faire disparaître silencieusement une
 *   configuration métier encore référencée.
 *
 * =====================================================================
 * DOUBLE GARANTIE D'UNICITÉ (analyse dédiée, validée)
 * =====================================================================
 * Une contrainte UNIQUE(supplier_id, product_id, product_variant_id)
 * seule ne suffit PAS : en SQL standard (SQLite comme PostgreSQL, les
 * deux moteurs réellement utilisés par ce projet — SQLite par défaut en
 * développement, PostgreSQL en production via pdo_pgsql/Dockerfile),
 * NULL n'est jamais égal à NULL pour l'évaluation d'une contrainte
 * UNIQUE : autant de lignes (fournisseur X, produit Y,
 * product_variant_id=NULL) que voulu passeraient silencieusement une
 * contrainte composée classique — limitation déjà connue et acceptée
 * sur warehouse_stocks (T11a), corrigée ici plutôt que reproduite :
 *
 * 1. UNIQUE(supplier_id, product_id, product_variant_id) — couvre
 *    correctement le cas "avec variante" (aucun NULL en jeu, la
 *    contrainte standard suffit).
 * 2. Index UNIQUE PARTIEL (supplier_id, product_id) WHERE
 *    product_variant_id IS NULL — couvre le cas "sans variante".
 *    Syntaxe strictement identique sur SQLite (>= 3.8.0, largement
 *    dépassée dans cet environnement) et PostgreSQL : contrairement à
 *    l'extension du CHECK sur stock_movements.type (T12/retour
 *    fournisseur), AUCUN branchement conditionnel par driver n'est
 *    nécessaire ici — une seule instruction SQL brute, exécutée telle
 *    quelle sur les deux moteurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_product_sourcing', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnDelete();

            $table->integer('priority')->default(100);
            $table->decimal('supplier_cost', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->integer('lead_time_days')->nullable();
            $table->integer('min_order_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            // Cas "avec variante" — suffisant seul (aucun NULL en jeu).
            $table->unique(
                ['supplier_id', 'product_id', 'product_variant_id'],
                'supplier_product_sourcing_unique_with_variant'
            );

            // Requêtes de sélection du meilleur sourcing (étapes
            // ultérieures D4+) : index préparé dès D1 car il ne porte
            // que sur des colonnes déjà présentes dans ce schéma.
            $table->index(
                ['product_id', 'product_variant_id', 'is_active', 'priority'],
                'supplier_product_sourcing_selection_index'
            );
        });

        // Cas "sans variante" (product_variant_id IS NULL) — index
        // UNIQUE PARTIEL, hors de portée du Schema Builder fluide de
        // Laravel (pas de clause WHERE sur ->unique()). Voir
        // documentation de tête de fichier.
        DB::statement(
            'CREATE UNIQUE INDEX supplier_product_sourcing_unique_no_variant '
            .'ON supplier_product_sourcing (supplier_id, product_id) '
            .'WHERE product_variant_id IS NULL'
        );
    }

    public function down(): void
    {
        // Schema::dropIfExists() supprime la table et l'ensemble de ses
        // index — y compris l'index partiel créé par DB::statement()
        // ci-dessus, sans étape de suppression séparée à écrire.
        Schema::dropIfExists('supplier_product_sourcing');
    }
};
