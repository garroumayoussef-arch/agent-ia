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
        'subtotal' => 'decimal:2',
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

        /*
         * subtotal = quantity_ordered * unit_price, recalculé
         * automatiquement tant que le bon de commande est en brouillon
         * (ou n'a pas encore de commande associée). Une fois sorti du
         * brouillon, ce hook ne touche plus subtotal : les montants
         * sont figés, exactement comme product_id/quantity_ordered
         * ci-dessous (PurchaseOrder::receive() ne modifie que
         * quantity_received, jamais quantity_ordered/unit_price, donc
         * rien ne redéclencherait ce calcul après confirmation).
         *
         * unit_price est nullable et aucune règle de prix par défaut
         * n'a été validée : subtotal reste alors NULL plutôt que
         * d'inventer un prix implicite de 0.
         */
        static::saving(function (PurchaseOrderItem $item): void {
            // Requête fraîche (pas l'accesseur de relation) : ce hook
            // s'exécute aussi à la création, et mettre `purchaseOrder`
            // en cache sur l'instance à ce moment-là ferait lire un
            // statut "draft" périmé au hook `updating` ci-dessous lors
            // d'une modification ultérieure sur cette même instance.
            $order = $item->purchaseOrder()->first();
            $isDraft = ! $order || $order->status === PurchaseOrder::STATUS_DRAFT;

            if (! $isDraft) {
                return;
            }

            $item->subtotal = $item->unit_price !== null
                ? round((int) $item->quantity_ordered * (float) $item->unit_price, 2)
                : null;
        });

        static::updating(function (PurchaseOrderItem $item) {
            /*
             * Une fois le bon de commande sorti du brouillon, la
             * définition de la ligne (produit/variante/quantité
             * commandée) et ses montants (prix unitaire, sous-total)
             * sont figés : seule PurchaseOrder::receive() peut encore
             * faire évoluer la ligne (quantity_received).
             */
            $order = $item->purchaseOrder;

            if (! $order || $order->status === PurchaseOrder::STATUS_DRAFT) {
                return;
            }

            foreach (['product_id', 'product_variant_id', 'quantity_ordered', 'unit_price', 'subtotal'] as $field) {
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

        /*
         * total (au niveau du bon de commande) = somme des subtotal de
         * ses lignes : recalculé après chaque création/modification
         * (`saved`, qui couvre les deux) ou suppression (`deleted`)
         * d'une ligne. PurchaseOrder::recalculateTotal() applique
         * elle-même la garde "brouillon uniquement", donc cet appel
         * reste un no-op inoffensif pendant receive() (qui ne modifie
         * que quantity_received).
         */
        static::saved(function (PurchaseOrderItem $item): void {
            $item->purchaseOrder()->first()?->recalculateTotal();
        });

        static::deleted(function (PurchaseOrderItem $item): void {
            $item->purchaseOrder()->first()?->recalculateTotal();
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
