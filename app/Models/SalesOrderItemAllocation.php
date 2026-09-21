<?php

namespace App\Models;

use App\Services\SupplierSourcingResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Chantier Dropshipping, étape D2.4.7 — instantané de la décision de
 * sourcing fournisseur pour une ligne de commande client (SalesOrderItem).
 * Capacité Core transversale : aucune référence à `activity` nulle part
 * dans ce fichier, comportement identique pour toutes les activités
 * utilisant Product.
 *
 * Principe central (validé) : SupplierSourcingResolver::best() (D2.4.6,
 * jamais modifié ici) DÉCIDE, cette classe PERSISTE la décision. Aucune
 * nouvelle règle de sélection fournisseur — recordFor() se contente
 * d'appeler le resolver existant et d'enregistrer son résultat.
 *
 * Point d'entrée UNIQUE de création : recordFor() — même convention que
 * PurchaseOrderItemReturn::recordFor()/CreditNoteLineReturn::recordFor()/
 * SupplierCreditNote::recordFor(). Lève une \Exception explicite (jamais
 * un retour null silencieux) si aucun sourcing actif n'est applicable ou
 * si une allocation existe déjà pour cette ligne : à la différence de
 * SupplierSourcingResolver::best() (une requête, dont l'absence de
 * résultat est une réponse légitime), recordFor() est une commande
 * explicitement invoquée pour créer un enregistrement — son échec doit
 * être visible immédiatement à l'appelant.
 *
 * Une seule allocation RACINE par SalesOrderItem (contrainte applicative
 * ci-dessous, `exists()` sur sales_order_item_id — portant sur TOUTE
 * allocation, historique ou active). Split multi-fournisseur et
 * réallocation AUTOMATIQUE explicitement hors périmètre de cette étape.
 *
 * Concurrence : DB::transaction() + lockForUpdate() sur la
 * SalesOrderItem elle-même, même mécanisme que
 * PurchaseOrderItemReturn::recordFor() — sérialise deux tentatives
 * concurrentes d'allocation de la même ligne.
 *
 * Hors périmètre (délibérément absent de ce fichier) : PurchaseOrder,
 * StockMovement, toute logique de stock, expédition, appel API
 * fournisseur, orchestration automatique, notification/mail, interface
 * Filament, Policy Laravel, nouvelle permission.
 *
 * ============================================================
 * Chantier Dropshipping, étape D2.9 (spécification validée) — mise à
 * jour de cette documentation
 * ============================================================
 * La contrainte UNIQUE(sales_order_item_id) mentionnée ci-dessus dans
 * les versions antérieures de ce commentaire N'EXISTE PLUS depuis D2.9
 * (migration dédiée) : elle interdisait structurellement toute
 * ré-allocation additive (qui exige de conserver l'ancienne allocation
 * tout en créant une nouvelle ligne pour la même sales_order_item_id).
 * recordFor() ci-dessous n'est PAS modifiée : sa garde `exists()` reste
 * l'unique protection contre une deuxième allocation RACINE pour la
 * même ligne — elle suffisait déjà seule avant D2.9, la contrainte
 * UNIQUE n'étant qu'un filet redondant.
 *
 * D2.9 ajoute un DEUXIÈME point d'entrée de création, reallocateFor()
 * (en bas de ce fichier) : crée une allocation ADDITIVE qui remplace une
 * allocation existante (colonne replaces_allocation_id, auto-référence,
 * UNIQUE en base), déclenchée UNIQUEMENT après un retour intégral au
 * fournisseur initial (sum(returns.quantity) === quantity_received,
 * égalité stricte), excluant le fournisseur de l'allocation
 * immédiatement précédente de la recherche du fournisseur alternatif
 * (SupplierSourcingResolver::best(), toujours inchangée dans sa logique
 * de sélection, D2.4.6). Aucune automatisation : reallocateFor() n'est
 * jamais invoquée que par une action Filament explicite (App\Filament\
 * Resources\SalesOrders\Concerns\HasSalesOrderReallocationAction),
 * jamais par un Observer, un Event listener ou un hook déclenché
 * automatiquement.
 *
 * Immutabilité (D2.9, validée) : booted() ci-dessous bloque désormais
 * toute modification/suppression d'une allocation APRÈS création,
 * qu'elle soit racine ou remplaçante — formalise un fait déjà vrai en
 * pratique (aucun chemin applicatif, avant D2.9, n'a jamais modifié ou
 * supprimé une allocation), zéro changement de comportement existant.
 */
class SalesOrderItemAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\SalesOrderItemAllocationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (SalesOrderItemAllocation $allocation) {
            $allocation->user_id ??= auth()->id();
        });

        /*
         * =================================================================
         * IMMUABILITÉ (D2.9, validée) — même convention que
         * PurchaseOrderItemReturn/CreditNoteLineReturn/SupplierCreditNote :
         * aucune exception, blocage inconditionnel dès la création,
         * qu'une allocation soit une racine (recordFor()) ou une
         * remplaçante (reallocateFor()). Ne change aucun comportement
         * existant : aucun chemin applicatif, avant ce hook, n'a jamais
         * modifié ni supprimé une allocation (seuls des whereKey(...)
         * ->lockForUpdate() en LECTURE existent dans
         * CreatePurchaseOrdersFromAllocations et reallocateFor()
         * ci-dessous). Ne déclenche aucun workflow, aucune notification :
         * un simple rejet, comme les modèles cités ci-dessus.
         * =================================================================
         */
        static::updating(function (): void {
            throw new \Exception(
                'Une allocation de sourcing ne peut pas être modifiée après son enregistrement.'
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                'Une allocation de sourcing ne peut pas être supprimée après son enregistrement.'
            );
        });
    }

    /**
     * Enregistre l'allocation de cette ligne de commande au meilleur
     * sourcing fournisseur actif applicable, déterminé exclusivement par
     * SupplierSourcingResolver::best() (inchangé). Jamais confiance dans
     * un état potentiellement obsolète de $item : reverrouillée ici sous
     * transaction avant toute décision.
     */
    public static function recordFor(SalesOrderItem $item): self
    {
        return DB::transaction(function () use ($item) {
            $lockedItem = SalesOrderItem::query()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (static::where('sales_order_item_id', $lockedItem->id)->exists()) {
                throw new \Exception('Cette ligne de commande a déjà une allocation.');
            }

            $subject = $lockedItem->productVariant ?? $lockedItem->product;

            $sourcing = app(SupplierSourcingResolver::class)->best($subject);

            if ($sourcing === null) {
                throw new \Exception("Aucun sourcing fournisseur actif n'est applicable à cette ligne.");
            }

            return static::create([
                'sales_order_item_id' => $lockedItem->id,
                'supplier_product_sourcing_id' => $sourcing->id,
                'quantity' => $lockedItem->quantity_ordered,
            ]);
        });
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    /**
     * D2.18 : offre en lecture seule. Elle ne dispense jamais du recontrôle
     * transactionnel et doit être conservée par l'appelant de confiance.
     */
    public static function previewCancelledPurchaseRecovery(self $current): array
    {
        return static::cancelledPurchaseRecoveryOffer($current->getKey(), false);
    }

    /**
     * Préparation groupée de la modale. Instantané de lecture uniquement :
     * l'exécution repasse toujours par les requêtes fraîches et le resolver.
     */
    public static function previewCancelledPurchaseRecoveriesFor(SalesOrder $record): array
    {
        $sale = SalesOrder::with([
            'items.allocation.replacedBy',
            'items.allocation.supplierProductSourcing',
            'items.allocation.purchaseOrderItem.purchaseOrder.items.returns',
            'items.product.variants',
            'items.productVariant',
        ])->findOrFail($record->getKey());
        $sources = SupplierProductSourcing::whereIn('product_id', $sale->items->pluck('product_id')->unique())
            ->where('is_active', true)->orderBy('priority')->orderBy('id')
            ->with('supplier')->get()->groupBy('product_id');
        $offers = [];
        $reasons = [];
        foreach ($sale->items as $item) {
            $current = $item->allocation;
            if (! $current) {
                continue;
            }
            // Même palier que SupplierSourcingResolver : choisir le spécifique
            // AVANT l'exclusion ; ne jamais se rabattre après exclusion.
            $candidates = $sources->get($item->product_id, collect());
            if ($item->product_variant_id !== null) {
                $specific = $candidates->where('product_variant_id', $item->product_variant_id);
                $candidates = $specific->isNotEmpty() ? $specific : $candidates->whereNull('product_variant_id');
            }
            $alternative = $candidates->first(fn ($source) => $source->supplier_id !== $current->supplierProductSourcing?->supplier_id);
            try {
                $offers[$current->id] = static::cancelledPurchaseRecoveryOffer($current->id, false, [
                    'sale' => $sale, 'item' => $item, 'current' => $current, 'alternative' => $alternative,
                ]);
            } catch (\Exception $e) {
                $reasons[] = 'Ligne #'.$item->id.' : '.$e->getMessage();
            }
        }

        return ['offers' => $offers, 'reasons' => $reasons];
    }

    /**
     * Reprise manuelle distincte du retour intégral D2.9. Aucun achat ni stock.
     * Ordre : vente, allocation, achat, lignes d'achat, sourcings du produit.
     * Le générateur verrouille aussi l'allocation avant de créer son achat.
     * Les workflows de vente/achat verrouillent leur en-tête avant leurs lignes.
     */
    public static function recoverAfterCancelledPurchaseFor(self $current, array $confirmed): self
    {
        return DB::transaction(function () use ($current, $confirmed) {
            $offer = static::cancelledPurchaseRecoveryOffer($current->getKey(), true);

            if ($offer !== $confirmed) {
                throw new \Exception('La proposition de reprise a changé. Veuillez confirmer à nouveau.');
            }

            return static::create([
                'sales_order_item_id' => $offer['sales_order_item_id'],
                'supplier_product_sourcing_id' => $offer['sourcing_id'],
                'quantity' => $offer['quantity'],
                'replaces_allocation_id' => $offer['allocation_id'],
            ]);
        });
    }

    private static function cancelledPurchaseRecoveryOffer(int $allocationId, bool $lock, ?array $preview = null): array
    {
        if ($preview !== null) {
            $sale = $preview['sale'];
            $current = $preview['current'];
            $item = $preview['item'];
        } else {
            $initial = static::findOrFail($allocationId);
            $initialItem = $initial->salesOrderItem()->firstOrFail();
            $saleQuery = SalesOrder::whereKey($initialItem->sales_order_id);
            $sale = ($lock ? $saleQuery->lockForUpdate() : $saleQuery)->firstOrFail();
            $allocationQuery = static::whereKey($allocationId);
            $current = ($lock ? $allocationQuery->lockForUpdate() : $allocationQuery)->firstOrFail();
            $item = $current->salesOrderItem()->firstOrFail();
        }

        if ($item->sales_order_id !== $sale->id
            || ($preview !== null ? $current->replacedBy !== null : $current->replacedBy()->exists())
            || ($preview !== null ? $item->allocation?->id : $item->allocation()->value('sales_order_item_allocations.id')) !== $current->id) {
            throw new \Exception('Cette allocation est déjà reprise ou ne correspond plus à la vente.');
        }
        if ($sale->status !== SalesOrder::STATUS_CONFIRMED
            || ($preview !== null ? $sale->items->contains(fn ($line) => (int) $line->quantity_shipped !== 0) : $sale->items()->where('quantity_shipped', '!=', 0)->exists())) {
            throw new \Exception('La reprise exige une vente confirmée sans aucune expédition.');
        }

        $purchaseItem = $preview !== null ? $current->purchaseOrderItem : $current->purchaseOrderItem()->first();
        if (! $purchaseItem) {
            throw new \Exception('Cette allocation ne possède pas de ligne d’achat conservée.');
        }
        if ($preview !== null) {
            $purchase = $purchaseItem->purchaseOrder;
            if (! $purchase) {
                throw new \Exception('Cette allocation ne possède pas d’achat conservé.');
            }
            $lines = $purchase->items;
        } else {
            $purchaseQuery = PurchaseOrder::whereKey($purchaseItem->purchase_order_id);
            $purchase = ($lock ? $purchaseQuery->lockForUpdate() : $purchaseQuery)->firstOrFail();
            $linesQuery = $purchase->items()->orderBy('id');
            $lines = ($lock ? $linesQuery->lockForUpdate() : $linesQuery)->get();
        }
        $purchaseItem = $lines->firstWhere('id', $purchaseItem->id);

        if ($purchase->status !== PurchaseOrder::STATUS_CANCELLED
            || $lines->contains(fn ($line) => (int) $line->quantity_received !== 0)
            || ($preview !== null ? $lines->contains(fn ($line) => $line->returns->isNotEmpty()) : PurchaseOrderItemReturn::whereIn('purchase_order_item_id', $lines->modelKeys())->exists())) {
            throw new \Exception('La reprise exige un achat annulé sans aucune réception ni retour.');
        }

        // Stabilise les fiches existantes pendant la décision, sans changer le resolver.
        if ($lock) {
            SupplierProductSourcing::where('product_id', $item->product_id)
                ->orderBy('id')->lockForUpdate()->get();
        }
        $source = $preview !== null ? $current->supplierProductSourcing : $current->supplierProductSourcing()->first();
        $product = $item->product;
        $variant = $item->productVariant;
        if (! $purchaseItem || ! $source || ! $product
            || $purchaseItem->sales_order_item_allocation_id !== $current->id
            || $purchaseItem->product_id !== $item->product_id
            || $purchaseItem->product_variant_id !== $item->product_variant_id
            || $source->product_id !== $item->product_id
            || ($source->product_variant_id !== null && $source->product_variant_id !== $item->product_variant_id)
            || ($item->product_variant_id !== null && (! $variant || $variant->product_id !== $product->id))
            || ($variant === null && ($preview !== null ? $product->variants->isNotEmpty() : $product->variants()->exists()))
            || $purchase->supplier_id !== $source->supplier_id) {
            throw new \Exception('Les liens vente, achat, allocation et sourcing sont incohérents.');
        }
        if ($current->quantity <= 0 || $current->quantity !== $item->quantity_ordered
            || $current->quantity !== $purchaseItem->quantity_ordered) {
            throw new \Exception('Les quantités de vente, d’achat et d’allocation doivent être identiques et positives.');
        }

        $alternative = $preview !== null ? $preview['alternative'] : app(SupplierSourcingResolver::class)->best($variant ?? $product, $source->supplier_id);
        if (! $alternative || ! $alternative->is_active
            || $alternative->supplier_id === $source->supplier_id
            || $alternative->product_id !== $product->id
            || ($alternative->product_variant_id !== null && $alternative->product_variant_id !== $item->product_variant_id)
            || ! $alternative->supplier) {
            throw new \Exception('Aucun fournisseur alternatif compatible actif n’est disponible.');
        }

        return [
            'allocation_id' => $current->id,
            'sales_order_id' => $sale->id,
            'sales_order_item_id' => $item->id,
            'purchase_order_id' => $purchase->id,
            'purchase_order_item_id' => $purchaseItem->id,
            'purchase_reference' => $purchase->reference,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $current->quantity,
            'sourcing_id' => $alternative->id,
            'supplier_id' => $alternative->supplier_id,
            'supplier_name' => $alternative->supplier->name,
        ];
    }

    public function supplierProductSourcing(): BelongsTo
    {
        return $this->belongsTo(SupplierProductSourcing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Chantier Dropshipping, étape D2.6.2 — la ligne de commande
     * fournisseur (PurchaseOrderItem) qui a converti cette allocation en
     * achat effectif, si elle existe (App\Services\
     * CreatePurchaseOrdersFromAllocations, D2.6.3). Relation additive en
     * lecture seule : ne crée aucune nouvelle écriture sur
     * SalesOrderItemAllocation. Au plus une PurchaseOrderItem par
     * allocation (contrainte UNIQUE sur
     * purchase_order_items.sales_order_item_allocation_id) — sert
     * exclusivement au contrôle d'idempotence de D2.6.3, jamais à
     * recordFor() ci-dessus, qui reste inchangé.
     */
    public function purchaseOrderItem(): HasOne
    {
        return $this->hasOne(PurchaseOrderItem::class);
    }

    /**
     * Chantier Dropshipping, étape D2.9 — l'allocation historique que
     * CETTE allocation remplace, si elle a été créée par reallocateFor()
     * ci-dessous. Null pour toute allocation racine (créée par
     * recordFor()). Relation additive en lecture seule.
     */
    public function replacesAllocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_allocation_id');
    }

    /**
     * Chantier Dropshipping, étape D2.9 — l'allocation qui a remplacé
     * CELLE-CI, si elle a déjà été ré-allouée. Null tant qu'aucune
     * ré-allocation n'a eu lieu contre cette allocation. Relation
     * additive en lecture seule, utilisée par reallocateFor() ci-dessous
     * pour la garde d'idempotence (une allocation ne peut être remplacée
     * qu'une seule fois — UNIQUE(replaces_allocation_id) en base).
     */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_allocation_id');
    }

    /**
     * Chantier Dropshipping, étape D2.9 — DEUXIÈME point d'entrée de
     * création (recordFor() ci-dessus n'est pas modifiée), déclenché
     * UNIQUEMENT par une action Filament explicite (jamais par un
     * Observer/Event/hook automatique — cf. documentation de tête de
     * fichier). Crée une nouvelle allocation ADDITIVE qui remplace
     * $current, vers le meilleur fournisseur ALTERNATIF compatible.
     *
     * Éligibilité (spécification validée, vérifiée sur données FRAÎCHES,
     * jamais sur un état potentiellement obsolète de $current) :
     * 1. $current doit déjà avoir généré une PurchaseOrderItem (D2.6) —
     *    sans achat effectif, aucun retour n'est possible.
     * 2. Cette PurchaseOrderItem doit avoir été intégralement retournée
     *    au fournisseur : sum(returns.quantity) === quantity_received,
     *    ÉGALITÉ STRICTE (jamais >=, jamais si quantity_received === 0 —
     *    ce cas trivial 0 === 0 n'est PAS un retour intégral, il
     *    signifie qu'il n'y a jamais rien eu à retourner).
     *
     * Exclusion fournisseur (spécification validée) : seul le
     * fournisseur de $current (l'allocation IMMÉDIATEMENT précédente)
     * est exclu de SupplierSourcingResolver::best() — jamais tout
     * l'historique de la chaîne. Un fournisseur utilisé il y a deux
     * ré-allocations peut donc redevenir le meilleur alternatif et être
     * reproposé : comportement volontaire, pas une boucle infinie
     * puisque chaque ré-allocation est un acte manuel distinct.
     *
     * Idempotence / double-clic / concurrence (spécification validée,
     * jamais uniquement côté interface) :
     * - DB::transaction() englobe l'intégralité de la méthode : toute
     *   exception (déjà remplacée, retour non intégral, aucune
     *   alternative, violation de contrainte) provoque un ROLLBACK
     *   complet, aucune écriture partielle possible.
     * - lockForUpdate() sur $current elle-même (même mécanique que
     *   l'idempotence n°2 de CreatePurchaseOrdersFromAllocations) : sous
     *   PostgreSQL, sérialise réellement deux tentatives concurrentes de
     *   ré-allocation de la même allocation. Sous SQLite (verrouillage
     *   ligne-par-ligne non réel), la garde définitive contre un
     *   remplacement multiple reste dans tous les cas la contrainte
     *   UNIQUE(replaces_allocation_id) en base : une deuxième tentative
     *   concurrente échoue soit par erreur de verrouillage SQLite
     *   ("database is locked"), soit par violation de cette contrainte
     *   à l'INSERT — dans les deux cas à l'intérieur de la même
     *   transaction, donc avec ROLLBACK complet, jamais de double
     *   ré-allocation persistante.
     * - Re-vérification de replacedBy()->exists() APRÈS acquisition du
     *   verrou, jamais avant : lit l'état réellement à jour.
     *
     * Quantité (spécification validée) : reprise à l'identique de
     * $current->quantity, aucune saisie manuelle en V1.
     *
     * Transversalité : aucune référence à `activity`, aucune branche
     * conditionnelle par activité — comportement strictement identique
     * pour Sport, Bébé, Moto, Artisanat du Maroc, Dropshipping...
     */
    public static function reallocateFor(self $current): self
    {
        return DB::transaction(function () use ($current) {
            $locked = static::whereKey($current->id)->lockForUpdate()->firstOrFail();

            if ($locked->replacedBy()->exists()) {
                throw new \Exception('Cette allocation a déjà été remplacée par une ré-allocation.');
            }

            $purchaseOrderItem = $locked->purchaseOrderItem;

            if ($purchaseOrderItem === null) {
                throw new \Exception(
                    "Cette allocation n'a pas encore de commande fournisseur générée : aucune ré-allocation possible."
                );
            }

            $received = (int) $purchaseOrderItem->quantity_received;
            $returned = PurchaseOrderItemReturn::totalReturnedFor($purchaseOrderItem);

            if ($received <= 0 || $returned !== $received) {
                throw new \Exception(
                    "Le retour fournisseur n'est pas intégral pour cette allocation : la ré-allocation n'est pas possible."
                );
            }

            $item = $locked->salesOrderItem;
            $subject = $item->productVariant ?? $item->product;
            $excludedSupplierId = $locked->supplierProductSourcing->supplier_id;

            $alternative = app(SupplierSourcingResolver::class)->best($subject, $excludedSupplierId);

            if ($alternative === null) {
                throw new \Exception(
                    "Aucun fournisseur alternatif compatible n'est disponible pour cette ligne."
                );
            }

            return static::create([
                'sales_order_item_id' => $locked->sales_order_item_id,
                'supplier_product_sourcing_id' => $alternative->id,
                'quantity' => $locked->quantity,
                'replaces_allocation_id' => $locked->id,
            ]);
        });
    }
}
