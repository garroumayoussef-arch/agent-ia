<?php

namespace App\Models;

use App\Models\Concerns\ValidatesOperationWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Chantier "retour physique fournisseur" (décisions 1 à 6, validées) —
 * un événement de retour physique de marchandise vers un fournisseur,
 * déclaré contre une PurchaseOrderItem réceptionnée, entièrement
 * indépendant de SupplierInvoice/SupplierCreditNote (décision 1) : un
 * retour physique peut exister sans qu'aucun avoir fournisseur ne soit
 * jamais reçu en conséquence, et inversement.
 *
 * Immuable dès l'enregistrement (cf. booted() ci-dessous), à l'identique
 * de CreditNoteLineReturn/InvoicePayment/SupplierCreditNote — aucune
 * correction/annulation en V1.
 *
 * Point d'entrée UNIQUE de création : recordFor() — même convention que
 * CreditNoteLineReturn::recordFor()/SupplierCreditNote::recordFor().
 *
 * ============================================================
 * RÈGLE CENTRALE (décision 1, validée) : avoir financier ≠ retour
 * physique ≠ mouvement de stock
 * ============================================================
 * Aucune méthode de ce projet ne crée automatiquement de
 * PurchaseOrderItemReturn — seul un appel explicite à recordFor() peut
 * en générer un, et celui-ci génère TOUJOURS un StockMovement (décision
 * 3, validée) : contrairement à CreditNoteLineReturn (qui distingue
 * vendable/défectueux), un retour fournisseur est par définition une
 * expédition physique vers l'extérieur — il n'existe pas de variante où
 * la marchandise resterait en interne sans être réellement expédiée.
 *
 * ============================================================
 * RATTACHEMENT (décision 2, validée) : PurchaseOrderItem, jamais
 * PurchaseOrder ni SupplierInvoice
 * ============================================================
 * Seule PurchaseOrderItem porte une donnée produit/quantité fiable côté
 * achat (SupplierInvoice/SupplierCreditNote sont traités au montant
 * global, sans ligne — cf. SupplierCreditNote, en-tête de classe). Le
 * plafond de quantité retournable est donc quantity_received de CETTE
 * ligne — jamais quantity_ordered (on ne peut retourner que ce qui a
 * été physiquement reçu), et jamais lié au statut de PurchaseOrder
 * (décision 4, validée : aucune fenêtre temporelle, un retour reste
 * possible tant que quantity_received - déjà_retourné > 0).
 *
 * ============================================================
 * QUANTITÉ ET PRODUIT/VARIANTE
 * ============================================================
 * product_id/product_variant_id sont des copies figées (snapshot) de
 * PurchaseOrderItem au moment du retour, même convention que
 * CreditNoteLineReturn. PurchaseOrderItem.product_id est NOT NULL et
 * cascadeOnDelete (contrairement à CreditNoteLine) : la garde "produit
 * identifiable" ci-dessous est donc une pure défense en profondeur,
 * jamais atteignable en pratique par ce chemin (la suppression du
 * produit supprimerait la ligne elle-même en cascade avant d'atteindre
 * ce code).
 *
 * ============================================================
 * ENTREPÔT (décision 5, validée)
 * ============================================================
 * Même mécanisme exact que PurchaseOrder::receive() : un seul entrepôt
 * par déclaration de retour, jamais choisi silencieusement dès qu'une
 * ambiguïté réelle existe (ValidatesOperationWarehouse, composé
 * ci-dessous) — jamais déduit de l'entrepôt de réception d'origine (qui
 * pourrait avoir changé depuis, via un StockTransfer).
 *
 * ============================================================
 * CONCURRENCE / ATOMICITÉ — même mécanisme validé en
 * CreditNoteLineReturn (T-retour physique client)
 * ============================================================
 * 1. DB::transaction() + lockForUpdate() sur la PurchaseOrderItem
 *    elle-même : sérialise réellement deux enregistrements concurrents
 *    de retour pour LA MÊME ligne.
 * 2. L'INSERT du retour est exécuté AVANT la vérification (jamais
 *    l'inverse) : sur SQLite (aucun verrouillage ligne par ligne réel),
 *    c'est cet INSERT qui force le verrou d'écriture au niveau du
 *    fichier dès le début de la transaction.
 * 3. Vérification APRÈS écriture, dans la même transaction : si le
 *    cumul des retours dépasse quantity_received, une exception est
 *    levée (ROLLBACK SQL, jamais un appel à delete() qui échouerait de
 *    toute façon sur un modèle immuable) — le retour n'est jamais
 *    persisté, et le StockMovement n'est JAMAIS atteint dans le code,
 *    placé après cette vérification.
 * 4. Boucle de nouvelle tentative (20 essais, délai aléatoire) qui ne
 *    retente QUE les Illuminate\Database\QueryException (contention
 *    transitoire type "database is locked") — jamais l'exception
 *    métier "dépasse la quantité", qui se propage immédiatement,
 *    message inchangé.
 *
 * Quantités toujours des entiers (jamais de décimal) : un retour
 * physique porte sur des unités entières, jamais une fraction.
 */
class PurchaseOrderItemReturn extends Model
{
    use ValidatesOperationWarehouse;

    protected $guarded = [];

    private const MAX_ATTEMPTS = 20;

    protected $casts = [
        'returned_at' => 'date',
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrderItemReturn $return) {
            $return->user_id ??= auth()->id();
        });

        /*
         * =================================================================
         * IMMUABILITÉ (même décision que CreditNoteLineReturn/
         * SupplierCreditNote : aucune exception)
         * =================================================================
         */
        static::updating(function (): void {
            throw new \Exception(
                'Un retour fournisseur ne peut pas être modifié après son enregistrement.'
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                'Un retour fournisseur ne peut pas être supprimé après son enregistrement.'
            );
        });
    }

    /**
     * Enregistre un retour physique de marchandise vers le fournisseur,
     * contre une ligne de bon de commande réceptionnée. Jamais confiance
     * dans les valeurs venues de l'appelant : revérifiées intégralement
     * ici (quantité > 0, produit/variante identifiable, cumul jamais
     * supérieur à la quantité réceptionnée de la ligne, entrepôt valide),
     * sous transaction et verrouillage (cf. documentation de tête de
     * classe).
     */
    public static function recordFor(
        PurchaseOrderItem $item,
        int $quantity,
        string $returnedAt,
        ?int $warehouseId = null,
        ?string $reason = null,
        ?string $reference = null,
        ?string $notes = null,
    ): self {
        if ($quantity <= 0) {
            throw new \Exception('La quantité retournée doit être supérieure à zéro.');
        }

        // Défense en profondeur : PurchaseOrderItem.product_id est NOT
        // NULL et cascadeOnDelete, donc structurellement jamais blank
        // ici en pratique — cf. documentation de tête de classe.
        if (blank($item->product_id) && blank($item->product_variant_id)) {
            throw new \Exception(
                'Le retour physique nécessite un produit ou une variante identifiable sur cette ligne.'
            );
        }

        // Décision 5 (validée) — même barrière que PurchaseOrder::receive() :
        // jamais d'entrepôt choisi silencieusement dès qu'une ambiguïté
        // réelle existe.
        static::assertValidOperationWarehouse($warehouseId);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($item, $quantity, $returnedAt, $warehouseId, $reason, $reference, $notes) {
                    $lockedItem = PurchaseOrderItem::query()
                        ->whereKey($item->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $return = static::create([
                        'purchase_order_item_id' => $lockedItem->id,
                        'product_id' => $lockedItem->product_id,
                        'product_variant_id' => $lockedItem->product_variant_id,
                        'quantity' => $quantity,
                        'returned_at' => $returnedAt,
                        'reason' => $reason,
                        'reference' => $reference,
                        'notes' => $notes,
                    ]);

                    $totalReturned = static::where('purchase_order_item_id', $lockedItem->id)->sum('quantity');

                    if ($totalReturned > $lockedItem->quantity_received) {
                        // Rejet métier définitif : jamais retenté, message
                        // inchangé (distinct d'une QueryException technique
                        // ci-dessous).
                        throw new \Exception(
                            'La quantité retournée dépasse la quantité restant retournable sur cette ligne.'
                        );
                    }

                    // Décision 3 (validée) — TOUJOURS un StockMovement,
                    // sans exception : un retour fournisseur est par
                    // définition une expédition physique vers l'extérieur.
                    // Placé après la vérification du cumul ci-dessus :
                    // jamais atteint si le cumul est dépassé (rollback
                    // avant cette ligne).
                    $purchaseOrder = $lockedItem->purchaseOrder;

                    StockMovement::create([
                        'product_id' => $lockedItem->product_id,
                        'product_variant_id' => $lockedItem->product_variant_id,
                        'warehouse_id' => $warehouseId,
                        'type' => 'return_to_supplier',
                        'quantity' => $quantity,
                        'purchase_order_item_return_id' => $return->id,
                        'reference' => $reference ?? $purchaseOrder?->reference,
                        'notes' => "Retour physique fournisseur — BC {$purchaseOrder?->reference}",
                    ]);

                    return $return;
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // Contention transitoire uniquement (ex. "database is
                // locked" sous forte concurrence SQLite) — jamais un
                // dépassement de quantité, qui lève un \Exception simple,
                // non capturé ici, et se propage immédiatement.
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new \Exception(
                        "Impossible d'enregistrer le retour après ".self::MAX_ATTEMPTS." tentatives (contention trop forte).",
                        previous: $e,
                    );
                }

                usleep(random_int(5_000, 20_000));
            }
        }

        // Inatteignable (la boucle retourne ou lève systématiquement
        // ci-dessus) — présent uniquement pour la complétude de type.
        throw new \Exception("Échec inattendu de l'enregistrement du retour fournisseur.");
    }

    /**
     * Quantité déjà retournée pour une ligne de bon de commande donnée —
     * calculée à la demande, jamais un champ stocké. Utilisée à la fois
     * pour proposer les lignes encore retournables côté Filament
     * (confort d'UI, jamais la seule garde) et implicitement par
     * recordFor() ci-dessus (revérification applicative complète,
     * toujours refaite indépendamment).
     */
    public static function totalReturnedFor(PurchaseOrderItem $item): int
    {
        return (int) static::where('purchase_order_item_id', $item->id)->sum('quantity');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Le StockMovement généré par ce retour — toujours non-null (décision
     * 3, validée : aucune condition ne bloque sa création, contrairement
     * à CreditNoteLineReturn).
     */
    public function stockMovement(): HasOne
    {
        return $this->hasOne(StockMovement::class);
    }
}
