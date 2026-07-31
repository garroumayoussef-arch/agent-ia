<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
    ];

    protected static function booted(): void
    {
        static::created(function (StockMovement $movement) {

            $product = $movement->product;

            if (! $product) {
                return;
            }

            // Stock avant le mouvement
            $movement->stock_before = $product->stock;

            switch ($movement->type) {

                case 'purchase':
                case 'return':
                    $product->stock += $movement->quantity;
                    break;

                case 'sale':

                    if ($product->stock < $movement->quantity) {
                        throw new \Exception('Stock insuffisant pour effectuer cette vente.');
                    }

                    $product->stock -= $movement->quantity;
                    break;

                case 'adjustment':
                case 'inventory':
                    $product->stock = $movement->quantity;
                    break;

                case 'transfer':
                    // À développer lorsque nous créerons le module multi-entrepôts
                    break;
            }

            // Stock après le mouvement
            $movement->stock_after = $product->stock;

            // Sauvegarde du produit
            $product->save();

            // Sauvegarde du mouvement sans relancer l'événement
            $movement->saveQuietly();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}