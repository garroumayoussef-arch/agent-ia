<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
        static::creating(function (StockMovement $movement) {

            /*
             * =========================================================
             * RÉCUPÉRATION DU PRODUIT / DE LA VARIANTE
             * =========================================================
             */

            $variant = $movement->productVariant;
            $product = $movement->product;

            /*
             * Aucun produit ni variante associé
             */
            if (!$variant && !$product) {
                throw new \Exception(
                    'Le mouvement doit être associé à un produit ou à une variante.'
                );
            }

            /*
             * =========================================================
             * VALIDATION DE LA QUANTITÉ
             * =========================================================
             */

            if (in_array($movement->type, ['adjustment', 'inventory'], true)) {

                /*
                 * Pour adjustment / inventory :
                 * la quantité représente le STOCK FINAL.
                 * 0 est donc autorisé.
                 */
                if ($movement->quantity < 0) {
                    throw new \Exception(
                        'La quantité ne peut pas être négative.'
                    );
                }

            } else {

                /*
                 * Pour purchase / sale / return :
                 * il faut au minimum 1 article.
                 */
                if ($movement->quantity < 1) {
                    throw new \Exception(
                        'La quantité doit être supérieure à zéro.'
                    );
                }
            }

            /*
             * =========================================================
             * TRANSFERT
             * =========================================================
             */

            if ($movement->type === 'transfer') {
                throw new \Exception(
                    'Les transferts entre entrepôts ne sont pas encore pris en charge.'
                );
            }

            /*
             * =========================================================
             * TRANSACTION STOCK
             * =========================================================
             */

            DB::transaction(function () use (
                $movement,
                $variant,
                $product
            ) {

                /*
                 * =====================================================
                 * CAS 1 : VARIANTE
                 * =====================================================
                 */

                if ($variant) {

                    /*
                     * Stock avant
                     */
                    $movement->stock_before = (int) $variant->stock;

                    /*
                     * Achat / Retour
                     */
                    if (
                        $movement->type === 'purchase' ||
                        $movement->type === 'return'
                    ) {

                        $variant->stock += $movement->quantity;
                    }

                    /*
                     * Vente
                     */
                    elseif ($movement->type === 'sale') {

                        if ($variant->stock < $movement->quantity) {
                            throw new \Exception(
                                'Stock insuffisant pour effectuer cette vente sur cette variante.'
                            );
                        }

                        $variant->stock -= $movement->quantity;
                    }

                    /*
                     * Ajustement / Inventaire
                     *
                     * Ici quantity = nouveau stock total.
                     */
                    elseif (
                        $movement->type === 'adjustment' ||
                        $movement->type === 'inventory'
                    ) {

                        $variant->stock = $movement->quantity;
                    }

                    /*
                     * Stock après
                     */
                    $movement->stock_after = (int) $variant->stock;

                    /*
                     * Sauvegarde de la variante.
                     *
                     * Le ProductVariant peut ensuite
                     * resynchroniser le stock du produit parent.
                     */
                    $variant->save();

                    return;
                }

                /*
                 * =====================================================
                 * CAS 2 : PRODUIT SANS VARIANTE
                 * =====================================================
                 */

                $movement->stock_before = (int) $product->stock;

                /*
                 * Achat / Retour
                 */
                if (
                    $movement->type === 'purchase' ||
                    $movement->type === 'return'
                ) {

                    $product->stock += $movement->quantity;
                }

                /*
                 * Vente
                 */
                elseif ($movement->type === 'sale') {

                    if ($product->stock < $movement->quantity) {
                        throw new \Exception(
                            'Stock insuffisant pour effectuer cette vente.'
                        );
                    }

                    $product->stock -= $movement->quantity;
                }

                /*
                 * Ajustement / Inventaire
                 */
                elseif (
                    $movement->type === 'adjustment' ||
                    $movement->type === 'inventory'
                ) {

                    $product->stock = $movement->quantity;
                }

                /*
                 * Stock après
                 */
                $movement->stock_after = (int) $product->stock;

                /*
                 * Sauvegarde du produit
                 */
                $product->save();
            });
        });
    }

    /*
     * =============================================================
     * RELATION : PRODUIT
     * =============================================================
     */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /*
     * =============================================================
     * RELATION : VARIANTE
     * =============================================================
     */

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(
            ProductVariant::class,
            'product_variant_id'
        );
    }
}