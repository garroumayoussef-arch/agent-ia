<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class PurchaseOrder extends Model
{
    /** @use HasFactory<\Database\Factories\PurchaseOrderFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'order_date' => 'date',
        'total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    /*
     * =============================================================
     * STATUTS
     * =============================================================
     *
     * draft --confirm--> ordered --receive (partiel)--> partially_received --receive (solde)--> received
     *   \--cancel--> cancelled            \--cancel--> cancelled
     *
     * Une fois qu'au moins une ligne a été réceptionnée (partially_received
     * ou received), le bon de commande devient un historique permanent :
     * il ne peut plus être ni annulé, ni supprimé.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrder $order) {
            $order->status ??= self::STATUS_DRAFT;
            $order->user_id ??= auth()->id();
        });

        static::deleting(function (PurchaseOrder $order) {
            if ($order->items()->where('quantity_received', '>', 0)->exists()) {
                throw new \Exception(
                    'Impossible de supprimer un bon de commande dont au moins une ligne a déjà été réceptionnée.'
                );
            }
        });

        /*
         * Une fois le bon de commande sorti du brouillon, ses montants
         * (remise, total) sont figés au même titre que les lignes
         * elles-mêmes (cf. PurchaseOrderItem::updating) :
         * recalculateTotal() ne les touche déjà plus dans ce cas, mais
         * cette garde bloque en plus toute tentative de modification
         * directe (mass-assignment, tinker...).
         *
         * On lit $order->status (l'état courant de l'instance, pas
         * l'original) : markAsOrdered()/cancel()/refreshStatusFromItems()
         * ne modifient jamais discount_amount/total dans le même appel,
         * donc rien ne bloque ces transitions légitimes ; à l'inverse,
         * une tentative de faire passer discount_amount en même temps
         * qu'un changement de statut serait — à raison — rejetée.
         */
        static::updating(function (PurchaseOrder $order) {
            if ($order->status === self::STATUS_DRAFT) {
                return;
            }

            foreach (['discount_amount', 'total', 'tax_amount', 'total_ttc'] as $field) {
                if ($order->isDirty($field)) {
                    throw new \Exception(
                        "Impossible de modifier les montants d'un bon de commande qui n'est plus en brouillon."
                    );
                }
            }
        });

        /*
         * discount_amount est modifiable indépendamment des lignes :
         * son changement doit donc redéclencher le recalcul de
         * total/tax_amount/total_ttc, ce qui implique de réallouer la
         * TVA de TOUTES les lignes (cf. applyTaxAllocation).
         *
         * Important : on modifie $order->total/tax_amount/total_ttc ICI,
         * dans `saving`, pour qu'ils soient écrits dans le MÊME UPDATE
         * SQL que discount_amount — surtout ne pas appeler
         * recalculateTotal() (qui fait son propre ->update()) depuis un
         * hook `updated`/`saved` de ce même modèle : Eloquent ne
         * resynchronise `original` qu'après la fin complète du `save()`
         * en cours (cf. finishSave), donc discount_amount resterait vu
         * comme "dirty" par ce second appel imbriqué sur la même
         * instance, qui redéclencherait updated -> recalcul -> update ->
         * updated -> ... à l'infini (testé : memory_limit atteint après
         * quelques dizaines de milliers d'itérations lors de la mise au
         * point de discount_amount). La réécriture des lignes elles-mêmes
         * (par requête directe, dans applyTaxAllocation) reste sans
         * risque : ce sont des lignes d'un AUTRE modèle, pas $this.
         */
        static::saving(function (PurchaseOrder $order) {
            if ($order->status !== self::STATUS_DRAFT) {
                return;
            }

            if ($order->isDirty('discount_amount')) {
                $amounts = $order->applyTaxAllocation();
                $order->total = $amounts['total'];
                $order->tax_amount = $amounts['tax_amount'];
                $order->total_ttc = $amounts['total_ttc'];
            }
        });
    }

    /*
     * =============================================================
     * TRANSITIONS DE STATUT
     * =============================================================
     */

    /**
     * Confirme un bon de commande brouillon : il devient "commandé"
     * et n'est plus modifiable dans sa définition (lignes verrouillées,
     * cf. PurchaseOrderItem::updating).
     */
    public function markAsOrdered(): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new \Exception('Seul un bon de commande en brouillon peut être confirmé.');
        }

        if (! $this->items()->exists()) {
            throw new \Exception('Impossible de confirmer un bon de commande sans ligne.');
        }

        $this->update([
            'status' => self::STATUS_ORDERED,
            'order_date' => $this->order_date ?? now()->toDateString(),
        ]);
    }

    /**
     * Annule un bon de commande. Interdit dès qu'une réception a eu
     * lieu (cf. en-tête de classe) : il faut alors le laisser tel quel,
     * il restera "partially_received" comme trace fidèle de ce qui a
     * réellement été livré.
     */
    public function cancel(): void
    {
        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ORDERED], true)) {
            throw new \Exception(
                'Seul un bon de commande en brouillon ou commandé (sans réception) peut être annulé.'
            );
        }

        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    /**
     * Réceptionne tout ou partie des lignes de ce bon de commande.
     *
     * Chaque quantité reçue génère un StockMovement (type purchase) —
     * toute la logique de stock (synchronisation variante/produit,
     * verrouillage, validation) est ainsi intégralement réutilisée
     * depuis StockMovement, sans duplication.
     *
     * @param  array<int|string, int|string>  $receivedQuantities  [purchase_order_item_id => quantité reçue maintenant]
     */
    public function receive(array $receivedQuantities): void
    {
        if (! in_array($this->status, [self::STATUS_ORDERED, self::STATUS_PARTIALLY_RECEIVED], true)) {
            throw new \Exception(
                'Seul un bon de commande commandé ou partiellement reçu peut être réceptionné.'
            );
        }

        $receivedQuantities = array_filter(
            $receivedQuantities,
            fn ($qty): bool => (int) $qty > 0
        );

        if ($receivedQuantities === []) {
            throw new \Exception("Aucune quantité à réceptionner n'a été renseignée.");
        }

        DB::transaction(function () use ($receivedQuantities) {
            $items = $this->items()
                ->whereIn('id', array_keys($receivedQuantities))
                ->get()
                ->keyBy('id');

            foreach ($receivedQuantities as $itemId => $quantityNow) {
                $item = $items->get((int) $itemId);

                if (! $item) {
                    continue;
                }

                $quantityNow = (int) $quantityNow;
                $remaining = $item->quantity_ordered - $item->quantity_received;

                if ($quantityNow > $remaining) {
                    throw new \Exception(
                        "La quantité reçue pour la ligne #{$item->id} ({$quantityNow}) dépasse la quantité restant à recevoir ({$remaining})."
                    );
                }

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'purchase_order_id' => $this->id,
                    'type' => 'purchase',
                    'quantity' => $quantityNow,
                    'reference' => $this->reference,
                    'notes' => "Réception bon de commande {$this->reference}",
                ]);

                $item->update([
                    'quantity_received' => $item->quantity_received + $quantityNow,
                ]);
            }

            $this->refreshStatusFromItems();
        });
    }

    /**
     * Recalcule le statut global (partially_received / received) à
     * partir de l'état de réception réel de chaque ligne.
     */
    protected function refreshStatusFromItems(): void
    {
        $items = $this->items()->get();

        $fullyReceived = $items->isNotEmpty() && $items->every(
            fn (PurchaseOrderItem $item): bool => $item->quantity_received >= $item->quantity_ordered
        );

        $anyReceived = $items->contains(
            fn (PurchaseOrderItem $item): bool => $item->quantity_received > 0
        );

        $this->update([
            'status' => match (true) {
                $fullyReceived => self::STATUS_RECEIVED,
                $anyReceived => self::STATUS_PARTIALLY_RECEIVED,
                default => $this->status,
            },
        ]);
    }

    /**
     * Recalcule total/tax_amount/total_ttc à partir des lignes (cf.
     * applyTaxAllocation). Appelée par PurchaseOrderItem à chaque
     * ajout/modification/suppression de ligne — sur une instance
     * fraîchement chargée (`$item->purchaseOrder()->first()`), jamais
     * en ré-entrance sur elle-même, donc son propre ->update() est sûr
     * ici (contrairement à un changement de discount_amount, cf.
     * static::saving ci-dessus).
     *
     * Ne fait rien tant que le bon de commande n'est plus en
     * brouillon : les montants sont figés à la confirmation exactement
     * comme le sont déjà les lignes elles-mêmes (cf.
     * PurchaseOrderItem::updating) — receive() ne modifie que
     * quantity_received, jamais les lignes financières ni
     * discount_amount, mais cette garde reste la barrière de dernier
     * recours si cette méthode venait à être appelée depuis un autre
     * point d'entrée.
     */
    public function recalculateTotal(): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            return;
        }

        $this->update($this->applyTaxAllocation());
    }

    /**
     * Calcule total (HT après remise), tax_amount et total_ttc, et
     * réécrit au passage le tax_amount de CHAQUE ligne au prorata de la
     * remise — c'est ce qui garantit que le tax_amount de la commande
     * (somme exacte des tax_amount de lignes déjà arrondis, aucun
     * ajustement séparé nécessaire) reste toujours réconciliable avec
     * ses lignes.
     *
     * Allocation de la remise (fixe, en EUR) au prorata de la base HT
     * de chaque ligne, PUIS taxation de la base ainsi réduite :
     *   part_remise_ligne = subtotal_ligne / somme(subtotal) * discount_amount
     *   base_taxable_ligne = max(0, subtotal_ligne - part_remise_ligne)
     *   tax_amount_ligne = base_taxable_ligne * tax_rate_ligne / 100
     *     (0 si la ligne est exonérée, NULL si son taux n'a pas pu
     *     être résolu)
     *
     * gross_tax_amount (calculé localement par chaque ligne dans son
     * propre `saving`, cf. PurchaseOrderItem) n'est jamais modifié ici
     * : il reste la TVA théorique SANS remise, à titre de traçabilité
     * interne, jamais confondue avec tax_amount.
     *
     * Propagation de l'inconnu, comme pour subtotal/total : si au
     * moins une ligne n'a pas de subtotal connu, tout redevient NULL
     * (impossible de répartir une remise sur une base partiellement
     * inconnue). Si le total est connu mais qu'au moins une ligne n'a
     * pas de taux résolu, tax_amount/total_ttc de la commande
     * deviennent NULL (chaque ligne affiche néanmoins ce qu'elle peut).
     *
     * Écrit les lignes par requête directe (`whereKey()->update()`),
     * jamais via `save()` : cela réécrirait `tax_amount` en passant par
     * `saving()`/`updating()` de PurchaseOrderItem et redéclencherait
     * son propre hook `saved` -> recalculateTotal() -> ... en boucle.
     *
     * @return array{total: ?float, tax_amount: ?float, total_ttc: ?float}
     */
    private function applyTaxAllocation(): array
    {
        $items = $this->items()->get(['id', 'subtotal', 'tax_rate_id', 'tax_rate']);

        if ($items->contains(fn (PurchaseOrderItem $item): bool => $item->subtotal === null)) {
            PurchaseOrderItem::whereIn('id', $items->pluck('id'))->update(['tax_amount' => null]);

            return ['total' => null, 'tax_amount' => null, 'total_ttc' => null];
        }

        $subtotalSum = round((float) $items->sum('subtotal'), 2);
        $total = max(0.0, round($subtotalSum - (float) $this->discount_amount, 2));

        $anyUnresolved = false;
        $orderTax = 0.0;

        foreach ($items as $item) {
            if ($item->tax_rate_id === null) {
                $anyUnresolved = true;
                PurchaseOrderItem::whereKey($item->id)->update(['tax_amount' => null]);

                continue;
            }

            $discountShare = $subtotalSum > 0
                ? ((float) $item->subtotal / $subtotalSum) * (float) $this->discount_amount
                : 0.0;
            $taxableBase = max(0.0, round((float) $item->subtotal - $discountShare, 2));

            $lineTax = $item->tax_rate === null
                ? 0.0 // ligne exonérée (type=exempt)
                : round($taxableBase * (float) $item->tax_rate / 100, 2);

            PurchaseOrderItem::whereKey($item->id)->update(['tax_amount' => $lineTax]);

            $orderTax += $lineTax;
        }

        $taxAmount = $anyUnresolved ? null : round($orderTax, 2);
        $totalTtc = $taxAmount === null ? null : round($total + $taxAmount, 2);

        return ['total' => $total, 'tax_amount' => $taxAmount, 'total_ttc' => $totalTtc];
    }

    /*
     * =============================================================
     * RELATIONS
     * =============================================================
     */

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
