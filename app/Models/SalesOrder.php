<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SalesOrder extends Model
{
    /** @use HasFactory<\Database\Factories\SalesOrderFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'order_date' => 'date',
        'total' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    /*
     * =============================================================
     * STATUTS
     * =============================================================
     *
     * draft --confirm--> confirmed --ship (partiel)--> partially_shipped --ship (solde)--> shipped
     *   \--cancel--> cancelled              \--cancel--> cancelled
     *
     * Même logique que PurchaseOrder : une fois qu'au moins une ligne a
     * été expédiée, la commande devient un historique permanent (plus
     * ni annulable, ni supprimable).
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PARTIALLY_SHIPPED = 'partially_shipped';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_CANCELLED = 'cancelled';

    protected static function booted(): void
    {
        static::creating(function (SalesOrder $order) {
            $order->status ??= self::STATUS_DRAFT;
            $order->user_id ??= auth()->id();
        });

        static::deleting(function (SalesOrder $order) {
            if ($order->items()->where('quantity_shipped', '>', 0)->exists()) {
                throw new \Exception(
                    'Impossible de supprimer une commande dont au moins une ligne a déjà été expédiée.'
                );
            }
        });

        /*
         * Une fois la commande sortie du brouillon, ses montants
         * (remise, total) sont figés au même titre que les lignes
         * elles-mêmes (cf. SalesOrderItem::updating) :
         * recalculateTotal() ne les touche déjà plus dans ce cas, mais
         * cette garde bloque en plus toute tentative de modification
         * directe (mass-assignment, tinker...).
         *
         * On lit $order->status (l'état courant de l'instance, pas
         * l'original) : markAsConfirmed()/cancel()/refreshStatusFromItems()
         * ne modifient jamais discount_amount/total dans le même appel,
         * donc rien ne bloque ces transitions légitimes ; à l'inverse,
         * une tentative de faire passer discount_amount en même temps
         * qu'un changement de statut serait — à raison — rejetée.
         */
        static::updating(function (SalesOrder $order) {
            if ($order->status === self::STATUS_DRAFT) {
                return;
            }

            foreach (['discount_amount', 'total'] as $field) {
                if ($order->isDirty($field)) {
                    throw new \Exception(
                        "Impossible de modifier les montants d'une commande qui n'est plus en brouillon."
                    );
                }
            }
        });

        /*
         * discount_amount est modifiable indépendamment des lignes :
         * son changement doit donc redéclencher le recalcul de total.
         *
         * Important : on modifie $order->total ICI, dans `saving`, pour
         * qu'il soit écrit dans le MÊME UPDATE SQL que discount_amount
         * — surtout ne pas appeler recalculateTotal() (qui fait son
         * propre ->update()) depuis un hook `updated`/`saved` de ce
         * même modèle : Eloquent ne resynchronise `original` qu'après
         * la fin complète du `save()` en cours (cf. finishSave), donc
         * discount_amount resterait vu comme "dirty" par ce second
         * appel imbriqué sur la même instance, qui redéclencherait
         * updated -> recalcul -> update -> updated -> ... à l'infini
         * (testé : memory_limit atteint après quelques dizaines de
         * milliers d'itérations).
         */
        static::saving(function (SalesOrder $order) {
            if ($order->status !== self::STATUS_DRAFT) {
                return;
            }

            if ($order->isDirty('discount_amount')) {
                $order->total = $order->computeTotalFromSubtotals();
            }
        });
    }

    /*
     * =============================================================
     * TRANSITIONS DE STATUT
     * =============================================================
     */

    /**
     * Confirme une commande brouillon : elle devient "confirmée" et
     * n'est plus modifiable dans sa définition (lignes verrouillées,
     * cf. SalesOrderItem::updating).
     */
    public function markAsConfirmed(): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new \Exception('Seule une commande en brouillon peut être confirmée.');
        }

        if (! $this->items()->exists()) {
            throw new \Exception('Impossible de confirmer une commande sans ligne.');
        }

        $this->update([
            'status' => self::STATUS_CONFIRMED,
            'order_date' => $this->order_date ?? now()->toDateString(),
        ]);
    }

    /**
     * Annule une commande. Interdit dès qu'une expédition a eu lieu :
     * elle reste alors "partially_shipped" comme trace fidèle de ce qui
     * a réellement été livré.
     */
    public function cancel(): void
    {
        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CONFIRMED], true)) {
            throw new \Exception(
                'Seule une commande en brouillon ou confirmée (sans expédition) peut être annulée.'
            );
        }

        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    /**
     * Expédie tout ou partie des lignes de cette commande.
     *
     * Chaque quantité expédiée génère un StockMovement (type sale) —
     * la validation du stock disponible (rejet si insuffisant) est
     * intégralement déléguée à StockMovement::applyMovementEffect(),
     * sans duplication de logique.
     *
     * @param  array<int|string, int|string>  $shippedQuantities  [sales_order_item_id => quantité expédiée maintenant]
     */
    public function ship(array $shippedQuantities): void
    {
        if (! in_array($this->status, [self::STATUS_CONFIRMED, self::STATUS_PARTIALLY_SHIPPED], true)) {
            throw new \Exception(
                'Seule une commande confirmée ou partiellement expédiée peut être expédiée.'
            );
        }

        $shippedQuantities = array_filter(
            $shippedQuantities,
            fn ($qty): bool => (int) $qty > 0
        );

        if ($shippedQuantities === []) {
            throw new \Exception("Aucune quantité à expédier n'a été renseignée.");
        }

        DB::transaction(function () use ($shippedQuantities) {
            $items = $this->items()
                ->whereIn('id', array_keys($shippedQuantities))
                ->get()
                ->keyBy('id');

            foreach ($shippedQuantities as $itemId => $quantityNow) {
                $item = $items->get((int) $itemId);

                if (! $item) {
                    continue;
                }

                $quantityNow = (int) $quantityNow;
                $remaining = $item->quantity_ordered - $item->quantity_shipped;

                if ($quantityNow > $remaining) {
                    throw new \Exception(
                        "La quantité expédiée pour la ligne #{$item->id} ({$quantityNow}) dépasse la quantité restant à expédier ({$remaining})."
                    );
                }

                // Si le stock est insuffisant, StockMovement::create()
                // lève une exception ici même : la transaction englobante
                // annule alors TOUTE l'expédition en cours (y compris les
                // lignes déjà traitées dans cette même boucle), sans
                // écriture partielle.
                StockMovement::create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'sales_order_id' => $this->id,
                    'type' => 'sale',
                    'quantity' => $quantityNow,
                    'reference' => $this->reference,
                    'notes' => "Expédition commande {$this->reference}",
                ]);

                $item->update([
                    'quantity_shipped' => $item->quantity_shipped + $quantityNow,
                ]);
            }

            $this->refreshStatusFromItems();
        });
    }

    /**
     * Recalcule le statut global (partially_shipped / shipped) à partir
     * de l'état d'expédition réel de chaque ligne.
     */
    protected function refreshStatusFromItems(): void
    {
        $items = $this->items()->get();

        $fullyShipped = $items->isNotEmpty() && $items->every(
            fn (SalesOrderItem $item): bool => $item->quantity_shipped >= $item->quantity_ordered
        );

        $anyShipped = $items->contains(
            fn (SalesOrderItem $item): bool => $item->quantity_shipped > 0
        );

        $this->update([
            'status' => match (true) {
                $fullyShipped => self::STATUS_SHIPPED,
                $anyShipped => self::STATUS_PARTIALLY_SHIPPED,
                default => $this->status,
            },
        ]);
    }

    /**
     * Recalcule total à partir de la somme des subtotal de ses lignes
     * (cf. computeTotalFromSubtotals). Appelée par SalesOrderItem à
     * chaque ajout/modification/suppression de ligne — sur une instance
     * fraîchement chargée (`$item->salesOrder()->first()`), jamais en
     * ré-entrance sur elle-même, donc son propre ->update() est sûr ici
     * (contrairement à un changement de discount_amount, cf.
     * static::saving ci-dessus).
     *
     * Ne fait rien tant que la commande n'est plus en brouillon : les
     * montants sont figés à la confirmation exactement comme le sont
     * déjà les lignes elles-mêmes (cf. SalesOrderItem::updating) —
     * ship() ne modifie que quantity_shipped, jamais les lignes
     * financières ni discount_amount, mais cette garde reste la
     * barrière de dernier recours si cette méthode venait à être
     * appelée depuis un autre point d'entrée.
     */
    public function recalculateTotal(): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            return;
        }

        $this->update(['total' => $this->computeTotalFromSubtotals()]);
    }

    /**
     * total = somme des subtotal des lignes - discount_amount (remise
     * fixe en EUR), jamais en dessous de 0.
     *
     * Si au moins une ligne n'a pas de subtotal connu (unit_price non
     * renseigné), retourne NULL plutôt que de sommer partiellement et
     * donner une fausse impression de complétude — même règle que
     * celle déjà appliquée à subtotal au niveau de la ligne ; la remise
     * ne s'applique alors pas non plus, faute de montant de base connu.
     */
    private function computeTotalFromSubtotals(): ?float
    {
        $subtotals = $this->items()->get(['subtotal'])->pluck('subtotal');

        if ($subtotals->contains(null)) {
            return null;
        }

        $subtotalSum = round((float) $subtotals->sum(), 2);

        return max(0.0, round($subtotalSum - (float) $this->discount_amount, 2));
    }

    /*
     * =============================================================
     * RELATIONS
     * =============================================================
     */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
