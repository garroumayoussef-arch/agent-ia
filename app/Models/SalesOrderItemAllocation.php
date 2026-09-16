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
 * Une seule allocation par SalesOrderItem dans cette étape (contrainte
 * UNIQUE en base sur sales_order_item_id, jamais uniquement applicative —
 * même philosophie que la double garantie d'unicité de
 * supplier_product_sourcing, D1). Split multi-fournisseur et
 * réallocation automatique explicitement hors périmètre de cette étape.
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
}
