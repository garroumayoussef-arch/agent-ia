<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 1 (plan de refactorisation MAGARROU ERP, étape 5/6) — mirroir
 * exact de product_attribute_values (étape 4/6), pour les attributs de
 * niveau VARIANTE (cf. attribute_definitions.level = 'variant', étape
 * 3/6, ex. cylindrée pour Moto, matière pour Artisanat...).
 *
 * Seule migration du Tier 1 qui référence `product_variants` par FK —
 * strictement nécessaire et prévue par le plan à cette étape précise
 * (l'objet même de cette table est de porter des valeurs d'attribut
 * pour une variante). Cette FK ne modifie AUCUNE colonne de
 * `product_variants` : elle crée une nouvelle table qui pointe vers son
 * `id` existant, exactement comme le font déjà stock_movements,
 * purchase_order_items ou sales_order_items dans ce projet — aucune
 * ALTER TABLE product_variants n'est émise ici. `size`, `color`,
 * `version` (colonnes Sport existantes sur product_variants) restent
 * strictement inchangés et continuent d'être la seule source utilisée
 * par Sport jusqu'au nettoyage Tier 2 (différé).
 *
 * Purement additive par ailleurs : ne touche à AUCUNE autre table
 * (`products`, `categories`, `attribute_definitions`,
 * `product_attribute_values` non plus).
 *
 * cascadeOnDelete sur product_variant_id ET attribute_definition_id,
 * unique(product_variant_id, attribute_definition_id) : mêmes
 * justifications que product_attribute_values (étape 4/6) — métadonnée
 * pure (pas d'historique légal/comptable à préserver), une seule valeur
 * par attribut et par variante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_attribute_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_variant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('attribute_definition_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('value')->nullable();

            $table->timestamps();

            $table->unique(['product_variant_id', 'attribute_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_attribute_values');
    }
};
