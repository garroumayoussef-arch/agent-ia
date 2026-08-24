<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Étape T10 — seeder de RÉCONCILIATION, pas de démonstration (même
 * catégorie que RoleSeeder/TaxRateSeeder/FiscalSettingSeeder : destiné
 * à tourner en production, pas seulement en local).
 *
 * Prépare la future bascule du champ texte libre
 * ProductVariant.warehouse vers de vraies relations Warehouse (T11+),
 * SANS jamais modifier products/product_variants : uniquement des
 * SELECT en lecture, jamais un UPDATE. Un Warehouse est pré-créé pour
 * chaque valeur distincte déjà saisie, afin que la bascule future soit
 * une simple correspondance nom -> id, jamais une création à l'aveugle.
 *
 * Vérifié précisément avant d'écrire ce seeder : contrairement à ce que
 * suggère ProductForm.php (qui expose un champ 'warehouse' dans le
 * formulaire Produit), la table `products` n'a PAS de colonne
 * `warehouse` — seule `product_variants` en a une. Ce champ de
 * ProductForm référence donc une colonne inexistante (anomalie
 * préexistante, hors périmètre de T10 — ni Product ni ProductVariant
 * ne sont modifiés ici). Seule product_variants est donc lue ci-dessous.
 *
 * Un entrepôt supplémentaire, marqué is_default, sert de repli pour
 * les variantes dont warehouse est NULL aujourd'hui.
 */
class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $this->importDistinctWarehouseNamesFrom('product_variants');

        Warehouse::firstOrCreate(
            ['code' => 'defaut'],
            ['name' => 'Entrepôt principal', 'is_default' => true],
        );
    }

    /**
     * Lecture seule sur $table (SELECT DISTINCT) — aucune écriture,
     * jamais un UPDATE sur products/product_variants.
     */
    private function importDistinctWarehouseNamesFrom(string $table): void
    {
        $names = DB::table($table)
            ->whereNotNull('warehouse')
            ->where('warehouse', '!=', '')
            ->distinct()
            ->pluck('warehouse');

        foreach ($names as $name) {
            Warehouse::firstOrCreate(
                ['name' => $name],
                ['code' => Str::slug($name)],
            );
        }
    }
}
