<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 1 (plan de refactorisation MAGARROU ERP, étape 1/6) — première
 * pierre du socle générique multi-activités (Sport / VTC / Bébé / Moto /
 * Artisanat, cinq activités sœurs de même niveau, aucune n'étant le
 * modèle central du Core).
 *
 * Purement additive : ajoute UNE colonne, ne touche à AUCUNE colonne
 * existante de `products`, ne supprime rien, ne renomme rien.
 *
 * `default('sport')` plutôt que `nullable()` : toutes les lignes
 * existantes sont exclusivement du catalogue Sport à ce jour (aucune
 * autre activité n'a encore de produit) — la valeur par défaut backfill
 * donc automatiquement ces lignes à la création de la colonne, sans
 * script de migration de données séparé et sans jamais réécrire une
 * valeur métier déjà présente sur ces lignes (aucune des colonnes
 * existantes n'est lue ni modifiée par cette migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('activity')->default('sport')->after('reference')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('activity');
        });
    }
};
