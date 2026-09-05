<?php

namespace Database\Seeders;

use App\Models\AttributeDefinition;
use Illuminate\Database\Seeder;

/**
 * Tier 2 (préparation), étape 2.1 — peuplement des définitions de
 * référence du système d'attributs génériques créé au Tier 1 (étape
 * 3/6), pour les 6 colonnes Sport dont la migration vers ce système a
 * été validée : `season`, `taille`, `equipe` (niveau produit), `size`,
 * `color`, `version` (niveau variante).
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
 * Purement additif : n'écrit que dans `attribute_definitions` (table
 * créée vide au Tier 1, non lue par le reste de l'application à ce
 * stade). Ne lit et ne modifie ni `products`, ni `product_variants`,
 * ni aucune autre table. `updateOrCreate` par `code` (contrainte
 * UNIQUE existante, étape 3/6) garantit l'idempotence : ré-exécuter ce
 * seeder ne crée jamais de doublon.
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
                'activity' => 'sport',
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
        ];

        foreach ($definitions as $definition) {
            AttributeDefinition::updateOrCreate(
                ['code' => $definition['code']],
                $definition
            );
        }
    }
}
