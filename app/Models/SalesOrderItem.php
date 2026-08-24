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
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'gross_tax_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
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

        /*
         * subtotal = quantity_ordered * unit_price, recalculé
         * automatiquement tant que la commande est en brouillon (ou n'a
         * pas encore de commande associée). Une fois sortie du
         * brouillon, ce hook ne touche plus subtotal : les montants
         * sont figés, exactement comme product_id/quantity_ordered
         * ci-dessous (SalesOrder::ship() ne modifie que
         * quantity_shipped, jamais quantity_ordered/unit_price, donc
         * rien ne redéclencherait ce calcul après confirmation).
         *
         * unit_price est nullable et aucune règle de prix par défaut
         * n'a été validée : subtotal reste alors NULL plutôt que
         * d'inventer un prix implicite de 0.
         */
        static::saving(function (SalesOrderItem $item): void {
            // Requête fraîche (pas l'accesseur de relation) : ce hook
            // s'exécute aussi à la création, et mettre `salesOrder` en
            // cache sur l'instance à ce moment-là ferait lire un statut
            // "draft" périmé au hook `updating` ci-dessous lors d'une
            // modification ultérieure sur cette même instance.
            $order = $item->salesOrder()->first();
            $isDraft = ! $order || $order->status === SalesOrder::STATUS_DRAFT;

            if (! $isDraft) {
                return;
            }

            $item->subtotal = $item->unit_price !== null
                ? round((int) $item->quantity_ordered * (float) $item->unit_price, 2)
                : null;

            /*
             * Résolution du taux de TVA applicable à cette ligne
             * (explicite sur la ligne > taux de vente par défaut du
             * produit > taux de vente par défaut système). gross_tax_amount
             * est la TVA théorique sur le subtotal SANS tenir compte de
             * la remise de commande — c'est une valeur intermédiaire,
             * jamais affichée comme "la" TVA de la ligne.
             *
             * tax_amount est initialisé à gross_tax_amount ici (cas
             * "aucune remise") : il sera immédiatement recalculé au
             * prorata de la remise réelle par
             * SalesOrder::recalculateTotal(), appelé juste après par le
             * hook `saved` ci-dessous — cf. sa documentation pour le
             * détail de l'allocation.
             */
            $taxRate = $item->resolveSaleTaxRate();

            if ($taxRate === null) {
                $item->tax_rate_id = null;
                $item->tax_rate = null;
                $item->gross_tax_amount = null;
            } elseif ($taxRate->isExempt()) {
                $item->tax_rate_id = $taxRate->id;
                $item->tax_rate = null;
                $item->gross_tax_amount = $item->subtotal !== null ? 0.0 : null;
            } else {
                $item->tax_rate_id = $taxRate->id;
                $item->tax_rate = (float) $taxRate->rate;
                $item->gross_tax_amount = $item->subtotal !== null
                    ? round((float) $item->subtotal * (float) $taxRate->rate / 100, 2)
                    : null;
            }

            $item->tax_amount = $item->gross_tax_amount;
        });

        static::updating(function (SalesOrderItem $item) {
            /*
             * Une fois la commande sortie du brouillon, la définition de
             * la ligne (produit/variante/quantité commandée) et ses
             * montants (prix unitaire, sous-total) sont figés : seule
             * SalesOrder::ship() peut encore faire évoluer la ligne
             * (quantity_shipped).
             */
            $order = $item->salesOrder;

            if (! $order || $order->status === SalesOrder::STATUS_DRAFT) {
                return;
            }

            foreach ([
                'product_id', 'product_variant_id', 'quantity_ordered', 'unit_price', 'subtotal',
                'tax_rate_id', 'tax_rate', 'gross_tax_amount', 'tax_amount',
            ] as $field) {
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

        /*
         * total (au niveau de la commande) = somme des subtotal de ses
         * lignes : recalculé après chaque création/modification
         * (`saved`, qui couvre les deux) ou suppression (`deleted`)
         * d'une ligne. SalesOrder::recalculateTotal() applique
         * elle-même la garde "brouillon uniquement", donc cet appel
         * reste un no-op inoffensif pendant ship() (qui ne modifie que
         * quantity_shipped).
         *
         * recalculateTotal() réécrit tax_amount de CETTE ligne (au
         * prorata de la remise) par requête directe, via
         * SalesOrder::applyTaxAllocation() — donc sur une instance PHP
         * différente de $item. Sans le refresh() ci-dessous, l'objet
         * $item resterait avec la valeur initiale (gross_tax_amount,
         * posée par le hook `saving` avant l'écriture) au lieu du
         * montant net réellement persisté : quiconque inspecte
         * $item->tax_amount juste après un create()/update() sans
         * ->fresh() verrait alors la TVA AVANT remise au lieu d'APRÈS.
         */
        static::saved(function (SalesOrderItem $item): void {
            $item->salesOrder()->first()?->recalculateTotal();
            $item->refresh();
        });

        static::deleted(function (SalesOrderItem $item): void {
            $item->salesOrder()->first()?->recalculateTotal();
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

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * Résout le taux de TVA applicable à cette ligne de vente :
     * explicite sur la ligne > taux de vente par défaut du produit >
     * taux de vente par défaut système. Requêtes fraîches (pas les
     * accesseurs de relation) pour ne rien mettre en cache sur cette
     * instance, appelée depuis `saving` (cf. sa documentation pour la
     * raison exacte).
     */
    private function resolveSaleTaxRate(): ?TaxRate
    {
        if ($this->tax_rate_id) {
            return $this->taxRate()->first();
        }

        $product = $this->product()->first();

        return $product?->saleTaxRate()->first()
            ?? TaxRate::where('is_default_sale', true)->first();
    }
}
