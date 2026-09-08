<?php

namespace Database\Seeders;

use App\Models\AttributeDefinition;
use Illuminate\Database\Seeder;

/**
 * Tier 2 (préparation), étape 2.1 — peuplement des définitions de
 * référence du système d'attributs génériques créé au Tier 1 (étape
 * 3/6), initialement pour les 6 colonnes Sport dont la migration vers
 * ce système a été validée : `season`, `taille`, `equipe` (niveau
 * produit), `size`, `color`, `version` (niveau variante).
 *
 * `products.version` est volontairement ABSENTE de cette liste : audit
 * dédié (Tier 2, résolution du conflit "version") ayant établi qu'elle
 * n'est exposée dans aucun formulaire Filament, lue par aucun code
 * applicatif, et peuplée uniquement par ProductFactory à des fins de
 * test — un concept mort, distinct de `product_variants.version` (le
 * seul réellement utilisé), qu'il ne faut pas migrer sous peine de
 * perpétuer l'ambiguïté de nommage. `version` ci-dessous est donc
 * exclusivement `level = 'variant'`.
 *
 * Étape 2.6.5 — extension du référentiel aux autres activités du Core
 * ERP, chacune au même niveau que Sport (aucune activité centrale ou
 * par défaut) :
 * - `color` devient TRANSVERSAL (`activity = null`) : seule définition
 *   existante modifiée (pas une nouvelle ligne, contrainte UNIQUE sur
 *   `code` oblige) — reflète sa nature réellement partagée entre
 *   activités, déjà anticipée par le commentaire d'origine de la
 *   migration `attribute_definitions` (Tier 1, étape 3/6).
 * - `size`/`version` restent scopés `sport` (liste d'options propre au
 *   textile, sans réutilisation possible telle quelle par les autres
 *   activités).
 * - Bébé (`tranche_age`, `taille_bebe`), Moto (`modele_compatible`,
 *   `cylindree`), Artisanat du Maroc (`matiere`, `region_origine`,
 *   `dimension`) : chacun avec son propre `code`, jamais une
 *   réutilisation d'un code Sport existant.
 * - VTC : aucune définition — n'utilise ni Product ni ProductVariant
 *   (cf. VtcRide, sans référence à Product/ProductVariant/StockMovement).
 * Total après cette étape : 13 définitions.
 *
 * Purement additif pour les 7 nouvelles lignes, avec une seule
 * modification ciblée (`color`) : n'écrit que dans
 * `attribute_definitions` (table créée vide au Tier 1, non lue par le
 * reste de l'application à ce stade). Ne lit et ne modifie ni
 * `products`, ni `product_variants`, ni aucune autre table.
 * `updateOrCreate` par `code` (contrainte UNIQUE existante, étape 3/6)
 * garantit l'idempotence : ré-exécuter ce seeder ne crée jamais de
 * doublon.
 *
 * Ce seeder n'est PAS enregistré dans DatabaseSeeder::run() à ce stade
 * — exécution isolée uniquement (`php artisan db:seed
 * --class=AttributeDefinitionSeeder`), tant que l'étape 2.1 n'a pas
 * été validée séparément.
 */
class AttributeDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'season',
                'label' => 'Saison',
                'activity' => 'sport',
                'level' => 'product',
                'input_type' => 'text',
                'options' => null,
                'is_required' => false,
            ],
            [
                'code' => 'taille',
                'label' => 'Taille',
                'activity' => 'sport',
                'level' => 'product',
                'input_type' => 'text',
                'options' => null,
                // products.taille n'est pas nullable (migration d'origine).
                'is_required' => true,
            ],
            [
                'code' => 'equipe',
                'label' => 'Équipe',
                'activity' => 'sport',
                'level' => 'product',
                'input_type' => 'text',
                'options' => null,
                'is_required' => false,
            ],
            [
                'code' => 'size',
                'label' => 'Taille',
                'activity' => 'sport',
                'level' => 'variant',
                'input_type' => 'select',
                'options' => ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'],
                'is_required' => false,
            ],
            [
                'code' => 'color',
                'label' => 'Couleur',
                // Étape 2.6.5 : transversal (null), plus scopé 'sport' —
                // seule définition existante modifiée dans cette étape,
                // jamais dupliquée (contrainte UNIQUE sur `code`).
                'activity' => null,
                'level' => 'variant',
                'input_type' => 'text',
                'options' => null,
                'is_required' => false,
            ],
            [
                'code' => 'version',
                'label' => 'Version',
                'activity' => 'sport',
                'level' => 'variant',
                'input_type' => 'select',
                'options' => ['Fan Version', 'Player Version', 'Kids', 'Training', 'Veste', 'Pantalon', 'Short'],
                'is_required' => false,
            ],

            // --- Bébé (étape 2.6.5) ---
            [
                'code' => 'tranche_age',
                'label' => 'Tranche d\'âge',
                'activity' => 'bebe',
                'level' => 'product',
                'input_type' => 'select',
                'options' => ['Naissance (0-1 mois)', 'Bébé (1-12 mois)', 'Bambin (1-3 ans)', 'Enfant (3-6 ans)'],
                'is_required' => false,
            ],
            [
                'code' => 'taille_bebe',
                'label' => 'Taille (bébé)',
                'activity' => 'bebe',
                'level' => 'variant',
                'input_type' => 'select',
                'options' => ['Naissance', '1 mois', '3 mois', '6 mois', '9 mois', '12 mois', '18 mois', '24 mois', '3 ans', '4 ans', '5 ans', '6 ans'],
                'is_required' => false,
            ],

            // --- Moto (étape 2.6.5) ---
            [
                'code' => 'modele_compatible',
                'label' => 'Modèle(s) compatible(s)',
                'activity' => 'moto',
                'level' => 'product',
                'input_type' => 'text',
                'options' => null,
                'is_required' => false,
            ],
            [
                'code' => 'cylindree',
                'label' => 'Cylindrée',
                'activity' => 'moto',
                'level' => 'variant',
                'input_type' => 'select',
                'options' => ['50cc', '125cc', '250cc', '500cc', '750cc', '1000cc+'],
                'is_required' => false,
            ],

            // --- Artisanat du Maroc (étape 2.6.5) ---
            [
                'code' => 'matiere',
                'label' => 'Matière',
                'activity' => 'artisanat',
                'level' => 'product',
                'input_type' => 'text',
                'options' => null,
                'is_required' => false,
            ],
            [
                'code' => 'region_origine',
                'label' => 'Région d\'origine',
                'activity' => 'artisanat',
                'level' => 'product',
                'input_type' => 'select',
                'options' => ['Fès', 'Marrakech', 'Essaouira', 'Safi', 'Chefchaouen', 'Rabat', 'Tétouan', 'Ouarzazate / Sud marocain', 'Agadir', 'Autre région'],
                'is_required' => false,
            ],
            [
                'code' => 'dimension',
                'label' => 'Dimension',
                'activity' => 'artisanat',
                'level' => 'variant',
                'input_type' => 'text',
                'options' => null,
                'is_required' => false,
            ],
        ];

        foreach ($definitions as $definition) {
            AttributeDefinition::updateOrCreate(
                ['code' => $definition['code']],
                $definition
            );
        }
    }
}
