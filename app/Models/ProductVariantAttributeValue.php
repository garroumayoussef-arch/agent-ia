<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tier 1 (plan de refactorisation MAGARROU ERP, étape 6/6) — modèle
 * Eloquent de la table `product_variant_attribute_values` (créée à
 * l'étape 5/6).
 *
 * Purement applicatif : aucune migration, aucune colonne. Les deux
 * relations belongsTo ci-dessous reflètent exactement les FK déjà
 * posées à l'étape 5/6 (`product_variant_id`, `attribute_definition_id`,
 * toutes deux en cascadeOnDelete) — rien n'est ajouté ni modifié
 * côté schéma.
 */
class ProductVariantAttributeValue extends Model
{
    protected $guarded = [];

    /**
     * Variante porteuse de cette valeur d'attribut.
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * Définition d'attribut dont cette ligne porte la valeur.
     */
    public function attributeDefinition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class);
    }
}
