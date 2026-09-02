<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 1 (plan de refactorisation MAGARROU ERP, étape 2/6) — même socle
 * générique multi-activités que la migration précédente
 * (add_activity_to_products_table), appliqué ici à `categories`.
 *
 * Purement additive : ajoute UNE colonne, ne touche à AUCUNE colonne
 * existante de `categories`, ne supprime rien, ne renomme rien.
 *
 * `nullable()`, SANS valeur par défaut (contrairement à
 * products.activity qui vaut 'sport' par défaut) : une catégorie
 * existante n'est pas automatiquement du Sport de manière aussi certaine
 * qu'un produit existant (le Core — Brand/Category — est déjà générique
 * et ne porte aujourd'hui aucune activité propre) ; classer chaque
 * catégorie reste une décision métier explicite, à faire par
 * l'administrateur (ou par une migration de données dédiée et
 * documentée), jamais présumée ici. Une catégorie non classée
 * (activity = NULL) reste neutre/transverse, ce qui est un état valide,
 * pas une erreur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('activity')->nullable()->after('parent_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('activity');
        });
    }
};
