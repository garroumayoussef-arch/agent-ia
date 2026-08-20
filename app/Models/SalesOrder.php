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
