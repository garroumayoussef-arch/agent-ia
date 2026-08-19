<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'stock' => 'integer',
        'prix_achat' => 'decimal:2',
        'prix_vente' => 'decimal:2',
    ];

    /**
     * Recalcule le stock du produit parent à partir de la somme
     * des stocks de toutes ses variantes.
     *
     * Point d'entrée UNIQUE de synchronisation Product.stock <-> variantes :
     * déclenché quel que soit le chemin d'écriture (mouvement de stock
     * via StockMovement, ou édition directe d'une variante dans le
     * formulaire Produit).
     */
    protected static function booted(): void
    {
        static::saved(function (ProductVariant $variant) {
            if ($variant->wasRecentlyCreated || $variant->wasChanged('stock')) {
                $variant->syncProductStock();
            }
        });

        static::deleted(function (ProductVariant $variant) {
            $variant->syncProductStock();
        });
    }

    /**
     * Met à jour Product.stock avec la somme des stocks de ses variantes.
     */
    protected function syncProductStock(): void
    {
        if (! $this->product_id) {
            return;
        }

        $total = static::where('product_id', $this->product_id)->sum('stock');

        Product::whereKey($this->product_id)->update(['stock' => $total]);
    }

    /**
     * Relation avec le produit parent.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relation avec les mouvements de stock de cette variante.
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(
            StockMovement::class,
            'product_variant_id'
        );
    }
}