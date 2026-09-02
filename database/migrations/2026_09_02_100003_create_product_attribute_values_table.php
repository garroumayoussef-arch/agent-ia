<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 1 (plan de refactorisation MAGARROU ERP, étape 4/6) — table de
 * valeurs pour les attributs de niveau PRODUIT (cf. attribute_definitions.
 * level = 'product', étape 3/6, ex. saison pour Sport, matière pour
 * Artisanat...). Ne concerne PAS ProductVariant : les attributs de
 * niveau variante (ex. taille/couleur) auront leur propre table dédiée
 * à une étape ultérieure du plan (product_variant_attribute_values),
 * non créée ici.
 *
 * Purement additive : CRÉE une nouvelle table indépendante. Aucune
 * colonne ajoutée sur `products` ni sur `attribute_definitions` — les FK
 * ci-dessous référencent leur `id` existant sans les altérer. Sport
 * continue de fonctionner exclusivement via ses colonnes dédiées
 * existantes sur `products` (club_id, competition_id, equipe, taille,
 * season, version), strictement inchangées : cette table reste vide et
 * inutilisée tant qu'aucune activité ne l'exploite (Tier 2, différé).
 *
 * cascadeOnDelete sur product_id : cohérent avec la convention déjà en
 * place dans ce projet pour les tables satellites purement descriptives
 * d'un produit (à la différence de stock_movements/purchase_order_items/
 * sales_order_items, qui sont un historique légal/comptable protégé par
 * Product::deleting() et donc en cascadeOnDelete pour une autre raison :
 * ici, une valeur d'attribut n'est qu'une métadonnée, sa suppression avec
 * le produit ne fait perdre aucune trace d'audit).
 *
 * cascadeOnDelete sur attribute_definition_id : si une définition
 * d'attribut est un jour supprimée, ses valeurs orphelines n'ont plus de
 * sens et doivent disparaître avec elle — décision symétrique, cohérente
 * avec le principe ci-dessus.
 *
 * unique(product_id, attribute_definition_id) : un produit ne peut avoir
 * qu'UNE seule valeur pour un même attribut (jamais deux lignes
 * concurrentes pour, par exemple, la "saison" d'un même produit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('attribute_definition_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('value')->nullable();

            $table->timestamps();

            $table->unique(['product_id', 'attribute_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_values');
    }
};
