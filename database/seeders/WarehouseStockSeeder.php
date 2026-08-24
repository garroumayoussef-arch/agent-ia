<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Seeder;

/**
 * Étape T11a — rétro-remplissage. Comme WarehouseSeeder (T10), c'est
 * un seeder de RÉCONCILIATION (destiné à tourner en production), pas
 * de démonstration.
 *
 * CONVENTION EXPLICITE, à respecter à l'identique en T11b pour les
 * mouvements historiques de stock_movements : tout stock existant
 * n'a jamais eu d'entrepôt suivi individuellement — il est donc
 * intégralement attribué à l'entrepôt is_default (créé par
 * WarehouseSeeder, T10). Ce n'est pas une approximation risquée : c'est
 * la seule représentation cohérente possible pour un historique qui
 * n'a jamais distingué les entrepôts. Quand T11b ajoutera warehouse_id
 * à stock_movements, les mouvements déjà enregistrés avant cette étape
 * devront être rattachés à ce même entrepôt par défaut, pour rester
 * cohérents avec le rétro-remplissage fait ici.
 *
 * Lecture seule sur products/product_variants (uniquement des
 * requêtes SELECT) — jamais un UPDATE. Un produit AVEC variantes ne
 * reçoit aucune ligne directe (product_variant_id = null) : seules ses
 * variantes en reçoivent une — même règle que
 * StockMovement::creating() (un produit ayant des variantes exige
 * qu'une variante soit précisée).
 */
class WarehouseStockSeeder extends Seeder
{
    public function run(): void
    {
        $default = Warehouse::where('is_default', true)->first();

        if (! $default) {
            // Défensif : ce seeder dépend de WarehouseSeeder (cf.
            // DatabaseSeeder). Sans lui, aucun entrepôt de repli
            // n'existe — on n'invente pas d'entrepôt ici.
            return;
        }

        Product::query()
            ->whereDoesntHave('variants')
            ->each(function (Product $product) use ($default) {
                WarehouseStock::firstOrCreate(
                    [
                        'warehouse_id' => $default->id,
                        'product_id' => $product->id,
                        'product_variant_id' => null,
                    ],
                    ['stock' => $product->stock],
                );
            });

        ProductVariant::query()
            ->each(function (ProductVariant $variant) use ($default) {
                WarehouseStock::firstOrCreate(
                    [
                        'warehouse_id' => $default->id,
                        'product_id' => $variant->product_id,
                        'product_variant_id' => $variant->id,
                    ],
                    ['stock' => $variant->stock],
                );
            });
    }
}
