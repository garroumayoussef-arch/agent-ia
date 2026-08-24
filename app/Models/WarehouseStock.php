<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape T11a — décomposition du stock par entrepôt. Table de données
 * pure à ce stade : peuplée une seule fois par le rétro-remplissage
 * (WarehouseStockSeeder), PAS encore maintenue à jour par
 * StockMovement (T11b). Ne jamais lire ce modèle comme une source de
 * vérité "live" avant que T11b ne soit en place — Product.stock/
 * ProductVariant.stock restent l'unique source de vérité à jour
 * jusque-là.
 */
class WarehouseStock extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stock' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
