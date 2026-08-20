<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\SalesOrderItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_shipped' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SalesOrderItem $item) {
            // Cohérence immédiate en mémoire avec le défaut DB (0).
            $item->quantity_shipped ??= 0;

            if ((int) $item->quantity_ordered < 1) {
                throw new \Exception('La quantité commandée doit être supérieure à zéro.');
            }

            $variant = $item->productVariant;
            $product = $item->product;

            /*
             * Même garde-fou que StockMovement::creating() /
             * PurchaseOrderItem::creating() : un produit qui a des
             * variantes ne peut pas être vendu "en gros", il faut
             * préciser la variante concernée.
             */
            if (! $variant && $product && $product->variants()->exists()) {
                throw new \Exception(
                    'Ce produit possède des variantes : veuillez sélectionner la variante concernée par cette ligne.'
                );
            }
        });

        static::updating(function (SalesOrderItem $item) {
            /*
             * Une fois la commande sortie du brouillon, la définition de
             * la ligne (produit/variante/quantité commandée) est figée :
             * seule SalesOrder::ship() peut encore la faire évoluer
             * (quantity_shipped).
             */
            $order = $item->salesOrder;

            if (! $order || $order->status === SalesOrder::STATUS_DRAFT) {
                return;
            }

            foreach (['product_id', 'product_variant_id', 'quantity_ordered'] as $field) {
                if ($item->isDirty($field)) {
                    throw new \Exception(
                        "Impossible de modifier une ligne dont la commande n'est plus en brouillon."
                    );
                }
            }
        });

        static::deleting(function (SalesOrderItem $item) {
            if ($item->quantity_shipped > 0) {
                throw new \Exception(
                    'Impossible de supprimer une ligne déjà (partiellement) expédiée.'
                );
            }
        });
    }

    /*
     * =============================================================
     * RELATIONS
     * =============================================================
     */

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
