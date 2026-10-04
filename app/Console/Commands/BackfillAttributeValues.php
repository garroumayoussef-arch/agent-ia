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
 * Réutilise la synchronisation des miroirs appelée par les hooks
 * `saved()`, sans appeler `save()` sur les sources : aucun hook de
 * normalisation, recalcul de stock ou UPDATE de leurs timestamps.
 * Les valeurs historiques sont copiées brutes selon les mêmes règles
 * d'applicabilité et de null que le dual-write ordinaire.
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
            $product->syncAttributeMirrors();
            $productCount++;
        });

        $this->info("Produits traités : {$productCount}");

        $variantCount = 0;

        ProductVariant::query()->each(function (ProductVariant $variant) use (&$variantCount): void {
            $variant->syncAttributeMirrors();
            $variantCount++;
        });

        $this->info("Variantes traitées : {$variantCount}");

        return self::SUCCESS;
    }
}
