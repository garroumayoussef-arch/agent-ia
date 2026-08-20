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
