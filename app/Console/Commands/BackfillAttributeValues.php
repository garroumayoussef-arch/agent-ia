<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;

/**
 * Tier 2 (préparation), étape 2.3 — peuple rétroactivement
 * `product_attribute_values`/`product_variant_attribute_values` pour
 * tous les Product/ProductVariant créés AVANT l'activation du
 * dual-write (étape 2.2, `Product::booted()`/`ProductVariant::booted()`).
 *
 * Aucune logique dupliquée : cette commande se contente d'appeler
 * `save()` sur chaque enregistrement existant, ce qui déclenche
 * exactement le même hook `saved()` déjà testé et validé à l'étape
 * 2.2 — même code, mêmes garanties (colonnes dédiées jamais
 * réécrites, activité réellement persistée lue depuis la base, aucun
 * hardcode d'activité). `Model::save()` n'exécute une requête UPDATE
 * que si le modèle est "dirty" (`isDirty()`) : un modèle rechargé sans
 * aucune modification ne déclenche donc AUCUNE écriture sur
 * `products`/`product_variants` — pas même `updated_at` — seul
 * l'événement `saved()` se déclenche, ce qui alimente les tables
 * miroir sans jamais toucher aux colonnes sources.
 *
 * Idempotente par construction : ré-exécuter cette commande rejoue les
 * mêmes `updateOrCreate()` déjà idempotents de l'étape 2.2, sans
 * jamais créer de doublon (contrainte UNIQUE déjà posée au Tier 1,
 * étapes 4/6 et 5/6).
 */
class BackfillAttributeValues extends Command
{
    protected $signature = 'attributes:backfill';

    protected $description = "Tier 2, étape 2.3 — peuple product_attribute_values/product_variant_attribute_values pour les Product/ProductVariant existants, via le dual-write de l'étape 2.2 (aucune colonne source modifiée).";

    public function handle(): int
    {
        $productCount = 0;

        Product::query()->each(function (Product $product) use (&$productCount): void {
            $product->save();
            $productCount++;
        });

        $this->info("Produits traités : {$productCount}");

        $variantCount = 0;

        ProductVariant::query()->each(function (ProductVariant $variant) use (&$variantCount): void {
            $variant->save();
            $variantCount++;
        });

        $this->info("Variantes traitées : {$variantCount}");

        return self::SUCCESS;
    }
}
