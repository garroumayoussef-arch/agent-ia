<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tier 1 (plan de refactorisation MAGARROU ERP, étape 6/6) — modèle
 * Eloquent de la table `attribute_definitions` (créée à l'étape 3/6).
 *
 * Purement applicatif : aucune migration, aucune colonne, cette classe
 * ne fait qu'exposer le schéma déjà posé. `level` distingue les
 * définitions portées par Product ('product') de celles portées par
 * ProductVariant ('variant') — cf. les deux relations ci-dessous, qui
 * pointent chacune vers la table de valeurs correspondante (étapes
 * 4/6 et 5/6), sans en modifier ni les colonnes ni les contraintes.
 */
class AttributeDefinition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
    ];

    /**
     * Valeurs de niveau produit associées à cette définition
     * (table `product_attribute_values`, étape 4/6).
     */
    public function productValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * Valeurs de niveau variante associées à cette définition
     * (table `product_variant_attribute_values`, étape 5/6).
     */
    public function variantValues(): HasMany
    {
        return $this->hasMany(ProductVariantAttributeValue::class);
    }
}
