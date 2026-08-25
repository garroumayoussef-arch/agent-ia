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
             * ENTREPÔT (dimension T11b)
             * =========================================================
             *
             * Un mouvement sans entrepôt explicite est rattaché à
             * l'unique entrepôt marqué par défaut. Aucun mouvement
             * n'est jamais créé avec warehouse_id = NULL : sans
             * entrepôt par défaut configuré, la création est refusée
             * explicitement plutôt que de laisser un mouvement
             * "orphelin" de toute dimension entrepôt.
             */

            if (!$movement->warehouse_id) {
                $movement->warehouse_id = static::resolveDefaultWarehouseIdOrFail();
            }

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

                    static::applyWarehouseStockEffect($movement);

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

                static::applyWarehouseStockEffect($movement);
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
             * Même principe pour l'entrepôt (T11b) : le déplacer
             * romprait la même façon la cohérence de warehouse_stocks
             * pour deux entrepôts différents.
             */
            if ($movement->isDirty('warehouse_id')) {
                throw new \Exception(
                    "Impossible de changer l'entrepôt d'un mouvement existant. Supprimez ce mouvement puis créez-en un nouveau."
                );
            }

            /*
             * =========================================================
             * INDIVISIBILITÉ D'UN TRANSFERT (T12)
             * =========================================================
             *
             * Un mouvement lié à un StockTransfer (transfer_out ou
             * transfer_in) fait partie d'une paire indivisible : le
             * modifier seul romprait l'effet net nul garanti sur le
             * stock global (Product/ProductVariant). Blocage total,
             * quel que soit le champ modifié — même un champ sans
             * impact sur le stock (notes, référence) — pour que le
             * transfert reste traité comme une seule unité.
             */
            if ($movement->stock_transfer_id !== null) {
                throw new \Exception(
                    "Impossible de modifier un mouvement appartenant à un transfert de stock. Le transfert est traité comme une unité indivisible."
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
            /*
             * Même garde d'indivisibilité qu'en édition (T12) : un
             * mouvement lié à un transfert ne peut jamais être
             * supprimé seul.
             */
            if ($movement->stock_transfer_id !== null) {
                throw new \Exception(
                    "Impossible de supprimer un mouvement appartenant à un transfert de stock. Le transfert est traité comme une unité indivisible."
                );
            }

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

    /**
     * Résout l'entrepôt par défaut (T11b). Utilisé aussi bien à la
     * création d'un mouvement sans warehouse_id explicite qu'au rejeu
     * (resyncLedger) d'un mouvement HISTORIQUE dont warehouse_id est
     * encore NULL (pas encore rattaché par StockMovementWarehouseSeeder).
     * Lève systématiquement une exception plutôt que de laisser
     * warehouse_id NULL ou 0 se propager vers warehouse_stocks.
     */
    private static function resolveDefaultWarehouseIdOrFail(): int
    {
        $defaultWarehouse = Warehouse::where('is_default', true)->first();

        if (!$defaultWarehouse) {
            throw new \Exception(
                "Aucun entrepôt par défaut n'est configuré : impossible de déterminer l'entrepôt de ce mouvement."
            );
        }

        return $defaultWarehouse->id;
    }

    private static function assertValidType(string $type): void
    {
        /*
         * 'transfer' reste réservé/bloqué : T12 introduit deux types
         * distincts et directionnels ('transfer_out'/'transfer_in',
         * créés exclusivement par StockTransfer::execute()), jamais
         * ce type générique sans direction.
         */
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

        /*
         * T12 — jambe sortante d'un transfert : même sémantique que
         * 'sale' (décrément, refus si insuffisant), appliquée soit au
         * stock global (temporairement, avant compensation par la
         * jambe transfer_in de la même transaction), soit au stock
         * d'un entrepôt précis via applyWarehouseStockEffect(). C'est
         * cette dernière application qui garantit la vérification
         * "stock insuffisant à la source" même si le stock global du
         * produit est suffisant ailleurs.
         */
        if ($type === 'transfer_out') {
            if ($stockBefore < $quantity) {
                throw new \Exception(
                    'Stock insuffisant dans l\'entrepôt source pour effectuer ce transfert.'
                );
            }

            return $stockBefore - $quantity;
        }

        /*
         * T12 — jambe entrante d'un transfert : même sémantique que
         * 'purchase' (incrément, jamais de refus). Combinée à
         * transfer_out dans la même transaction (StockTransfer::execute()),
         * l'effet net sur le stock global est toujours nul.
         */
        if ($type === 'transfer_in') {
            return $stockBefore + $quantity;
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

        /*
         * =========================================================
         * RECALCUL PAR ENTREPÔT (T11b)
         * =========================================================
         *
         * Rejeu ADDITIONNEL du même $ledger, cette fois partitionné
         * par warehouse_id, sans toucher au rejeu global ci-dessus
         * (ni à $runningStock, ni à la mise à jour de $target). Le
         * point d'ancrage est identique (le stock_before ORIGINAL du
         * tout premier mouvement), attribué à l'entrepôt de ce
         * premier mouvement — les autres entrepôts démarrent à 0,
         * n'ayant par construction aucun stock antérieur à leur
         * propre premier mouvement.
         *
         * Un mouvement HISTORIQUE pas encore rattaché par
         * StockMovementWarehouseSeeder (warehouse_id encore NULL) est
         * résolu ici via le même repli sur l'entrepôt par défaut que
         * `creating()` — jamais 0/NULL propagé vers warehouse_stocks.
         */
        $anchorWarehouseId = $anchor->warehouse_id
            ? (int) $anchor->warehouse_id
            : static::resolveDefaultWarehouseIdOrFail();

        $warehouseTotals = [$anchorWarehouseId => $baselineStock];

        foreach ($ledger as $entry) {
            $rawWarehouseId = ($pendingMovement && $entry->id === $pendingMovement->id)
                ? $pendingMovement->warehouse_id
                : $entry->warehouse_id;

            $entryWarehouseId = $rawWarehouseId
                ? (int) $rawWarehouseId
                : static::resolveDefaultWarehouseIdOrFail();

            $warehouseTotals[$entryWarehouseId] ??= 0;

            $warehouseTotals[$entryWarehouseId] = static::applyMovementEffect(
                $entry->type,
                (int) $entry->quantity,
                $warehouseTotals[$entryWarehouseId]
            );
        }

        foreach ($warehouseTotals as $warehouseId => $total) {
            $warehouseStock = WarehouseStock::firstOrCreate(
                [
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'product_variant_id' => $productVariantId,
                ],
                ['stock' => 0]
            );

            WarehouseStock::whereKey($warehouseStock->id)->update(['stock' => $total]);
        }
    }

    /**
     * Applique l'effet d'un mouvement (déjà validé) à la ligne
     * warehouse_stocks correspondante (T11b). Complète la mise à jour
     * du stock global (Product/ProductVariant) déjà effectuée par
     * l'appelant, sans la modifier.
     *
     * Si aucune ligne warehouse_stocks n'existe encore pour ce couple
     * (entrepôt, produit/variante) — cas d'un stock affecté
     * directement sans être jamais passé par WarehouseStockSeeder ni
     * par un mouvement — elle est créée avec pour stock initial
     * `$movement->stock_before` (le stock global juste AVANT ce
     * mouvement), pas 0 : même convention que WarehouseStockSeeder
     * (T11a), qui réputait tout stock non encore décomposé comme
     * entièrement présent dans l'entrepôt qui le reçoit. Partir de 0
     * romprait cet invariant et ferait apparaître un stock
     * insuffisant alors que le stock global, lui, est suffisant.
     *
     * Exception à cette règle (T12) : pour transfer_out/transfer_in,
     * ce raisonnement s'inverse. Ces types désignent explicitement
     * DEUX entrepôts déjà distingués l'un de l'autre — toucher pour la
     * première fois l'un des deux ne signifie pas qu'il détenait tout
     * le stock, bien au contraire (l'entrepôt destination d'un
     * transfert n'a, par définition, rien avant de recevoir). Une
     * ligne nouvellement créée pour ces deux types démarre donc
     * TOUJOURS à 0, jamais à `stock_before` — sans quoi une jambe
     * entrante vers un entrepôt jamais décomposé se retrouverait
     * indûment créditée du stock global entier en plus de la quantité
     * transférée (cf. StockTransferTest pour la preuve du cas).
     *
     * Limite connue : une création concurrente de cette ligne sur le
     * tout premier mouvement d'un couple (entrepôt, produit/variante)
     * peut en théorie entrer en conflit avec la contrainte d'unicité —
     * même limite déjà documentée pour WarehouseStockSeeder (T11a),
     * non traitée ici pour rester strictement dans le périmètre T11b/T12.
     */
    private static function applyWarehouseStockEffect(StockMovement $movement): void
    {
        $warehouseStock = WarehouseStock::where('warehouse_id', $movement->warehouse_id)
            ->where('product_id', $movement->product_id)
            ->where('product_variant_id', $movement->product_variant_id)
            ->first();

        if (!$warehouseStock) {
            $isTransferLeg = in_array($movement->type, ['transfer_out', 'transfer_in'], true);

            $warehouseStock = WarehouseStock::create([
                'warehouse_id' => $movement->warehouse_id,
                'product_id' => $movement->product_id,
                'product_variant_id' => $movement->product_variant_id,
                'stock' => $isTransferLeg ? 0 : (int) $movement->stock_before,
            ]);
        }

        // Verrouillage de la ligne pour éviter toute condition de
        // course entre deux mouvements concurrents sur le même couple
        // (entrepôt, produit/variante).
        $warehouseStock = WarehouseStock::whereKey($warehouseStock->id)
            ->lockForUpdate()
            ->first();

        $warehouseStock->stock = static::applyMovementEffect(
            $movement->type,
            (int) $movement->quantity,
            (int) $warehouseStock->stock
        );

        $warehouseStock->save();
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

    /*
     * =============================================================
     * RELATION : ENTREPÔT (T11b)
     * =============================================================
     */

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /*
     * =============================================================
     * RELATION : TRANSFERT DE STOCK D'ORIGINE (T12)
     * =============================================================
     */

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    /*
     * =============================================================
     * RELATION : UTILISATEUR À L'ORIGINE DU MOUVEMENT
     * =============================================================
     */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
     * =============================================================
     * RELATION : BON DE COMMANDE D'ORIGINE (si généré par une réception)
     * =============================================================
     */

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /*
     * =============================================================
     * RELATION : COMMANDE DE VENTE D'ORIGINE (si généré par une expédition)
     * =============================================================
     */

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
