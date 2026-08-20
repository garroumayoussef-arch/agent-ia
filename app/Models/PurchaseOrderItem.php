<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\PurchaseOrderItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrderItem $item) {
            // Cohérence immédiate en mémoire avec le défaut DB (0),
            // sans attendre un refresh() après la création.
            $item->quantity_received ??= 0;

            if ((int) $item->quantity_ordered < 1) {
                throw new \Exception('La quantité commandée doit être supérieure à zéro.');
            }

            $variant = $item->productVariant;
            $product = $item->product;

            /*
             * Même garde-fou que StockMovement::creating() : un produit
             * qui a des variantes ne peut pas être commandé "en gros",
             * il faut préciser la variante concernée.
             */
            if (! $variant && $product && $product->variants()->exists()) {
                throw new \Exception(
                    'Ce produit possède des variantes : veuillez sélectionner la variante concernée par cette ligne.'
                );
            }
        });

        static::updating(function (PurchaseOrderItem $item) {
            /*
             * Une fois le bon de commande sorti du brouillon, la
             * définition de la ligne (produit/variante/quantité
             * commandée) est figée : seule PurchaseOrder::receive() peut
             * encore la faire évoluer (quantity_received).
             */
            $order = $item->purchaseOrder;

            if (! $order || $order->status === PurchaseOrder::STATUS_DRAFT) {
                return;
            }

            foreach (['product_id', 'product_variant_id', 'quantity_ordered'] as $field) {
                if ($item->isDirty($field)) {
                    throw new \Exception(
                        "Impossible de modifier une ligne dont le bon de commande n'est plus en brouillon."
                    );
                }
            }
        });

        static::deleting(function (PurchaseOrderItem $item) {
            if ($item->quantity_received > 0) {
                throw new \Exception(
                    'Impossible de supprimer une ligne déjà (partiellement) réceptionnée.'
                );
            }
        });
    }

    /*
     * =============================================================
     * RELATIONS
     * =============================================================
     */

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
