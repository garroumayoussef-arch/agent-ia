<?php

namespace App\Models;

use App\Filament\Concerns\ScopesToOwnWarehouses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Étape T12 — transfert de stock entre deux entrepôts. Document
 * d'historique/traçabilité de l'opération ; l'effet réel sur le stock
 * (warehouse_stocks) passe par les deux StockMovement liés
 * (transfer_out / transfer_in) créés atomiquement par execute().
 *
 * Instantané et atomique en une seule opération (décision validée) :
 * pas de workflow brouillon/confirmé/en transit. Une fois créé, un
 * transfert est un enregistrement permanent — ni modifiable, ni
 * supprimable (cf. booted() ci-dessous), au même titre que ses deux
 * mouvements liés (garde symétrique posée sur StockMovement).
 */
class StockTransfer extends Model
{
    use ScopesToOwnWarehouses;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockTransfer $transfer) {
            if (!$transfer->from_warehouse_id || !$transfer->to_warehouse_id) {
                throw new \Exception(
                    "L'entrepôt source et l'entrepôt destination sont obligatoires."
                );
            }

            if ((int) $transfer->from_warehouse_id === (int) $transfer->to_warehouse_id) {
                throw new \Exception(
                    "L'entrepôt source et l'entrepôt destination doivent être différents."
                );
            }

            if ((int) $transfer->quantity <= 0) {
                throw new \Exception(
                    'La quantité transférée doit être supérieure à zéro.'
                );
            }

            /*
             * T19 (D4/D6) — contrôle d'appartenance, en plus des
             * contrôles métier T12 ci-dessus, inchangés : un manager
             * restreint doit avoir les DEUX entrepôts (source ET
             * destination) dans son périmètre, jamais un seul des deux.
             * Barrière autoritaire — vérifiée ici, jamais confiance
             * dans le filtrage des options du formulaire
             * (HasStockTransferAction), qu'un appel direct pourrait
             * contourner.
             */
            static::assertWarehouseIsInScope($transfer->from_warehouse_id);
            static::assertWarehouseIsInScope($transfer->to_warehouse_id);

            $transfer->user_id ??= auth()->id();
        });

        /*
         * Un transfert instantané n'a aucune raison légitime d'être
         * modifié après coup (pas de workflow, décision validée) :
         * bloqué sans exception, même principe que l'indivisibilité
         * de ses mouvements liés.
         */
        static::updating(function (StockTransfer $transfer) {
            throw new \Exception(
                "Un transfert de stock ne peut pas être modifié après sa création."
            );
        });

        /*
         * Protection applicative explicite, en plus de la contrainte
         * restrictOnDelete() déjà posée sur
         * stock_movements.stock_transfer_id (défense en profondeur,
         * même principe que Warehouse/WarehouseStock).
         */
        static::deleting(function (StockTransfer $transfer) {
            if ($transfer->stockMovements()->exists()) {
                throw new \Exception(
                    "Impossible de supprimer ce transfert : il possède des mouvements de stock liés."
                );
            }
        });
    }

    /**
     * Exécute un transfert de stock complet et atomique entre deux
     * entrepôts : crée le StockTransfer et ses deux StockMovement liés
     * (transfer_out à la source, transfer_in à la destination) dans
     * une seule transaction. Toute défaillance (stock insuffisant à la
     * source, entrepôts identiques, quantité invalide...) annule
     * l'ensemble — aucune écriture partielle.
     *
     * Les valeurs de $data ne sont jamais présumées fiables (jamais de
     * confiance dans ce qui provient du navigateur) : la vérification
     * de stock se fait ici en relisant l'état réel de warehouse_stocks
     * en base, sous verrou, au moment de l'exécution — pas sur une
     * valeur affichée côté client.
     */
    public static function execute(array $data): self
    {
        return DB::transaction(function () use ($data) {
            $fromWarehouseId = (int) ($data['from_warehouse_id'] ?? 0);
            $toWarehouseId = (int) ($data['to_warehouse_id'] ?? 0);
            $productId = (int) ($data['product_id'] ?? 0);
            $productVariantId = !empty($data['product_variant_id']) ? (int) $data['product_variant_id'] : null;
            $quantity = (int) ($data['quantity'] ?? 0);

            /*
             * Verrouillage des deux lignes warehouse_stocks concernées
             * (si elles existent déjà), dans un ordre DÉTERMINISTE
             * (warehouse_id croissant, indépendant du sens du
             * transfert) : évite tout interblocage entre deux
             * transferts concurrents en sens opposé sur les deux
             * mêmes entrepôts. Les verrous repris ensuite par
             * StockMovement::applyWarehouseStockEffect() sur les mêmes
             * lignes, dans la même transaction, sont sans effet
             * (déjà détenus).
             */
            foreach ([min($fromWarehouseId, $toWarehouseId), max($fromWarehouseId, $toWarehouseId)] as $warehouseId) {
                WarehouseStock::where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->where('product_variant_id', $productVariantId)
                    ->lockForUpdate()
                    ->first();
            }

            $transfer = static::create([
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'product_id' => $productId,
                'product_variant_id' => $productVariantId,
                'quantity' => $quantity,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            StockMovement::create([
                'product_id' => $productId,
                'product_variant_id' => $productVariantId,
                'warehouse_id' => $fromWarehouseId,
                'stock_transfer_id' => $transfer->id,
                'type' => 'transfer_out',
                'quantity' => $quantity,
                'reference' => $transfer->reference,
                'notes' => "Transfert #{$transfer->id} — sortie vers l'entrepôt destination",
                'user_id' => $transfer->user_id,
            ]);

            StockMovement::create([
                'product_id' => $productId,
                'product_variant_id' => $productVariantId,
                'warehouse_id' => $toWarehouseId,
                'stock_transfer_id' => $transfer->id,
                'type' => 'transfer_in',
                'quantity' => $quantity,
                'reference' => $transfer->reference,
                'notes' => "Transfert #{$transfer->id} — entrée depuis l'entrepôt source",
                'user_id' => $transfer->user_id,
            ]);

            return $transfer;
        });
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
