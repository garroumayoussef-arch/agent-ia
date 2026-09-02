<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 1 (plan de refactorisation MAGARROU ERP, étape 3/6) — table de
 * référence du futur système d'attributs génériques du catalogue.
 *
 * Purement additive : CRÉE une nouvelle table indépendante, ne touche à
 * AUCUNE table existante (`products`, `product_variants`, `categories`
 * compris) — aucune colonne ajoutée, aucune FK posée vers/depuis cette
 * étape sur une table déjà en place. Sport continue de fonctionner
 * exclusivement via ses colonnes dédiées existantes (club_id,
 * competition_id, equipe, taille, season, version) : cette table ne les
 * remplace pas encore (Tier 2, différé), elle ne fait qu'exister, vide,
 * en attendant que les prochaines activités (Bébé/Moto/Artisanat)
 * l'utilisent.
 *
 * `code` unique : identifiant stable référencé par le futur code
 * applicatif (ex. 'size', 'color', 'cylindree'), jamais l'id numérique.
 *
 * `activity` nullable (même convention que categories.activity, étape
 * 2/6) : NULL = attribut transverse partagé entre plusieurs activités
 * (ex. 'color'), une valeur = attribut scopé à une seule activité (ex.
 * 'cylindree' pour Moto uniquement).
 *
 * `level` : distingue un attribut porté par Product ('product', ex.
 * saison) d'un attribut porté par ProductVariant ('variant', ex.
 * taille/couleur) — cf. les deux tables de valeurs prévues aux étapes
 * suivantes du plan (product_attribute_values /
 * product_variant_attribute_values), non créées ici.
 *
 * `input_type` + `options` (JSON, nullable) : rendent un attribut
 * affichable dynamiquement dans Filament (texte libre vs liste
 * déroulante) sans nouvelle migration à chaque nouvel attribut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('label');
            $table->string('activity')->nullable()->index();
            $table->enum('level', ['product', 'variant']);
            $table->string('input_type')->default('text');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
