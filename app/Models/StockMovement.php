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
        /*
         * =================================================================
         * CRÉATION D'UN MOUVEMENT
         * =================================================================
         */

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
             * Le produit possède des variantes : un mouvement "produit
             * global" (sans variante) romprait la synchronisation
             * Product.stock = SUM(variantes.stock) assurée par
             * ProductVariant::syncProductStock(). Dans ce cas, une
             * variante doit obligatoirement être sélectionnée.
             *
             * Ce garde-fou existe au niveau du modèle (et pas uniquement
             * dans le formulaire Filament) pour rester valable quel que
             * soit le point d'entrée (UI, Tinker, API future...).
             */
            if (!$variant && $product && $product->variants()->exists()) {
                throw new \Exception(
                    'Ce produit possède des variantes : veuillez sélectionner la variante concernée par ce mouvement.'
                );
            }

            /*
             * =========================================================
             * UTILISATEUR À L'ORIGINE DU MOUVEMENT
             * =========================================================
             *
             * Renseigné automatiquement avec l'utilisateur authentifié,
             * sans jamais écraser une valeur déjà fournie explicitement
             * (ex : script d'import, tâche système attribuant le
             * mouvement à un utilisateur précis). `auth()->id()` renvoie
             * `null` en l'absence d'utilisateur connecté (CLI, tests,
             * job en file d'attente...) : la colonne restant nullable,
             * la création n'est jamais bloquée pour autant.
             */
            $movement->user_id ??= auth()->id();

            /*
             * =========================================================
             * VALIDATION DU TYPE ET DE LA QUANTITÉ
             * =========================================================
             */

            static::assertValidQuantity($movement->type, (int) $movement->quantity);
            static::assertValidType($movement->type);

            /*
             * =========================================================
             * TRANSACTION STOCK
             * =========================================================
             */

            DB::transaction(function () use ($movement, $variant, $product) {

                /*
                 * =====================================================
                 * CAS 1 : VARIANTE
                 * =====================================================
                 */

                if ($variant) {

                    // Verrouillage de la ligne pour éviter toute
                    // condition de course entre deux mouvements
                    // concurrents sur la même variante.
                    $variant = ProductVariant::whereKey($variant->id)
                        ->lockForUpdate()
                        ->first();

                    $movement->stock_before = (int) $variant->stock;
                    $movement->stock_after = static::applyMovementEffect(
                        $movement->type,
                        (int) $movement->quantity,
                        $movement->stock_before
                    );

                    $variant->stock = $movement->stock_after;

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

                $product = Product::whereKey($product->id)
                    ->lockForUpdate()
                    ->first();

                $movement->stock_before = (int) $product->stock;
                $movement->stock_after = static::applyMovementEffect(
                    $movement->type,
                    (int) $movement->quantity,
                    $movement->stock_before
                );

                $product->stock = $movement->stock_after;

                $product->save();
            });
        });

        /*
         * =================================================================
         * MODIFICATION D'UN MOUVEMENT EXISTANT
         * =================================================================
         */

        static::updating(function (StockMovement $movement) {

            /*
             * Changer la cible (produit ou variante) d'un mouvement déjà
             * enregistré demanderait de resynchroniser DEUX historiques
             * de stock (celui de l'ancienne cible et celui de la
             * nouvelle). Ce n'est pas une opération métier valide pour
             * un ERP : on supprime le mouvement et on en recrée un.
             */
            if ($movement->isDirty('product_id') || $movement->isDirty('product_variant_id')) {
                throw new \Exception(
                    "Impossible de changer le produit ou la variante d'un mouvement existant. Supprimez ce mouvement puis créez-en un nouveau."
                );
            }

            /*
             * Si ni le type ni la quantité ne changent, ce mouvement
             * n'a aucun impact sur le stock (ex : correction d'un
             * commentaire ou d'une référence) : rien à recalculer.
             */
            if (!$movement->isDirty('type') && !$movement->isDirty('quantity')) {
                return;
            }

            static::assertValidQuantity($movement->type, (int) $movement->quantity);
            static::assertValidType($movement->type);

            DB::transaction(function () use ($movement) {
                static::resyncLedger(
                    productVariantId: $movement->product_variant_id,
                    productId: $movement->product_id,
                    excludeMovementId: null,
                    pendingMovement: $movement,
                );
            });
        });

        /*
         * =================================================================
         * SUPPRESSION D'UN MOUVEMENT EXISTANT
         * =================================================================
         */

        static::deleting(function (StockMovement $movement) {
            DB::transaction(function () use ($movement) {
                static::resyncLedger(
                    productVariantId: $movement->product_variant_id,
                    productId: $movement->product_id,
                    excludeMovementId: $movement->id,
                    pendingMovement: null,
                );
            });
        });
    }

    /*
     * =============================================================
     * VALIDATION
     * =============================================================
     */

    private static function assertValidQuantity(string $type, int $quantity): void
    {
        if (in_array($type, ['adjustment', 'inventory'], true)) {

            /*
             * Pour adjustment / inventory :
             * la quantité représente le STOCK FINAL.
             * 0 est donc autorisé.
             */
            if ($quantity < 0) {
                throw new \Exception(
                    'La quantité ne peut pas être négative.'
                );
            }

            return;
        }

        /*
         * Pour purchase / sale / return :
         * il faut au minimum 1 article.
         */
        if ($quantity < 1) {
            throw new \Exception(
                'La quantité doit être supérieure à zéro.'
            );
        }
    }

    private static function assertValidType(string $type): void
    {
        if ($type === 'transfer') {
            throw new \Exception(
                'Les transferts entre entrepôts ne sont pas encore pris en charge.'
            );
        }
    }

    /**
     * Applique l'effet d'un mouvement (type + quantité) à un stock de
     * départ et renvoie le stock résultant. Lève une exception si le
     * mouvement est invalide (ex : vente supérieure au stock disponible).
     */
    private static function applyMovementEffect(string $type, int $quantity, int $stockBefore): int
    {
        if (in_array($type, ['purchase', 'return'], true)) {
            return $stockBefore + $quantity;
        }

        if ($type === 'sale') {
            if ($stockBefore < $quantity) {
                throw new \Exception(
                    'Stock insuffisant pour effectuer cette vente.'
                );
            }

            return $stockBefore - $quantity;
        }

        if (in_array($type, ['adjustment', 'inventory'], true)) {
            // Ici quantity = nouveau stock total.
            return $quantity;
        }

        throw new \Exception("Type de mouvement inconnu : {$type}.");
    }

    /**
     * Rejoue l'historique des mouvements d'une cible (variante, ou produit
     * sans variante) afin de recalculer stock_before/stock_after de
     * chaque mouvement ainsi que le stock courant de la cible.
     *
     * Appelé :
     * - à la MODIFICATION d'un mouvement (`$pendingMovement` porte les
     *   nouvelles valeurs type/quantity, pas encore persistées) ;
     * - à la SUPPRESSION d'un mouvement (`$excludeMovementId` retire ce
     *   mouvement du rejeu).
     *
     * Le point d'ancrage du rejeu est le `stock_before` ORIGINAL
     * (non modifié) du tout premier mouvement de la cible : c'est un
     * état antérieur à tout mouvement, donc indépendant des éditions/
     * suppressions ultérieures.
     *
     * Si le rejeu produit un état invalide (ex : une vente qui
     * deviendrait supérieure au stock disponible à ce moment de
     * l'historique), une exception est levée : l'appelant se trouve
     * dans la transaction DB ouverte par `updating`/`deleting`, qui est
     * donc annulée intégralement (aucune écriture partielle).
     */
    private static function resyncLedger(
        ?int $productVariantId,
        ?int $productId,
        ?int $excludeMovementId,
        ?self $pendingMovement,
    ): void {
        $query = static::query()->orderBy('id');

        if ($productVariantId) {
            $query->where('product_variant_id', $productVariantId);
        } else {
            $query->where('product_id', $productId)->whereNull('product_variant_id');
        }

        $allMovements = $query->get();

        $anchor = $allMovements->first();

        if (!$anchor) {
            return;
        }

        $baselineStock = (int) $anchor->getOriginal('stock_before');

        $ledger = $allMovements
            ->reject(fn (StockMovement $m) => $excludeMovementId && $m->id === $excludeMovementId)
            ->map(fn (StockMovement $m) => ($pendingMovement && $m->id === $pendingMovement->id) ? $pendingMovement : $m)
            ->values();

        // Verrouillage de la cible pendant tout le rejeu.
        $target = $productVariantId
            ? ProductVariant::whereKey($productVariantId)->lockForUpdate()->first()
            : Product::whereKey($productId)->lockForUpdate()->first();

        if (!$target) {
            return;
        }

        $runningStock = $baselineStock;

        foreach ($ledger as $entry) {
            $before = $runningStock;
            $after = static::applyMovementEffect($entry->type, (int) $entry->quantity, $before);

            if ($pendingMovement && $entry->id === $pendingMovement->id) {
                // Le mouvement en cours de modification : ses nouvelles
                // valeurs seront persistées par le `->save()` normal
                // déjà en cours (on est dans le hook `updating`).
                $entry->stock_before = $before;
                $entry->stock_after = $after;
            } elseif ((int) $entry->stock_before !== $before || (int) $entry->stock_after !== $after) {
                // Correction "silencieuse" d'un mouvement voisin dont
                // l'historique décale suite à l'édition/suppression :
                // simple mise à jour comptable, pas un nouvel événement
                // métier (saveQuietly évite toute récursion des hooks).
                $entry->forceFill([
                    'stock_before' => $before,
                    'stock_after' => $after,
                ])->saveQuietly();
            }

            $runningStock = $after;
        }

        // Synchronise le stock courant de la cible avec le résultat du
        // rejeu. Pour une variante, ceci déclenche normalement
        // ProductVariant::syncProductStock() afin de tenir Product.stock
        // à jour.
        $target->update(['stock' => $runningStock]);
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
