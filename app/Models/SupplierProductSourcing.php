<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Chantier Dropshipping, étape D1 — fiche de sourcing : déclare qu'un
 * Supplier peut fournir un Product/ProductVariant donné, à quel coût
 * déclaratif, avec quelle priorité et quel délai. Capacité Core,
 * réutilisable par n'importe quelle activité (aucune colonne
 * `activity`, aucune référence au dropshipping dans ce modèle) —
 * conformément à l'orientation validée : le dropshipping UTILISE cette
 * table, il ne la POSSÈDE pas.
 *
 * Table de configuration pure, jamais un enregistrement financier/audit
 * : contrairement à PurchaseOrderItem/StockMovement, sa disparition (via
 * cascadeOnDelete depuis product_id/product_variant_id) ne fait perdre
 * aucun historique — voir la migration pour le détail des FK et de la
 * double garantie d'unicité (avec/sans variante).
 *
 * Hors périmètre de D1 (étapes ultérieures) : sélection automatique du
 * meilleur fournisseur, allocation, expédition, orchestration — aucune
 * de ces logiques n'existe encore sur ce modèle, volontairement, pour
 * garder cette étape la plus étroite possible.
 */
class SupplierProductSourcing extends Model
{
    protected $table = 'supplier_product_sourcing';

    protected $guarded = [];

    protected $casts = [
        'priority' => 'integer',
        'supplier_cost' => 'decimal:2',
        'lead_time_days' => 'integer',
        'min_order_quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    /** D2.20 : inclut les allocations historiques, sans relation mise en cache. */
    public function hasAllocationHistory(): bool
    {
        return $this->exists && $this->getConnection()->table('sales_order_item_allocations')
            ->where('supplier_product_sourcing_id', $this->getRawOriginal($this->getKeyName()))
            ->exists();
    }

    /**
     * D2.20 : garde commune à save/update et à leurs variantes silencieuses.
     * PostgreSQL READ COMMITTED : FOR UPDATE entre en conflit avec le KEY
     * SHARE du contrôle FK d'une allocation. La lecture des références doit
     * être une requête distincte APRES l'acquisition de ce verrou.
     * SQLite sérialise les écritures ; un conflit doit annuler la transaction,
     * jamais conduire à rejouer uniquement l'UPDATE sans relire les références.
     * Les écritures SQL/de masse contournant le modèle restent hors contrat.
     */
    public function save(array $options = [])
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options) {
            $identity = ['supplier_id', 'product_id', 'product_variant_id'];
            $pending = array_intersect_key($this->getDirty(), array_flip($identity));

            // Une édition opérationnelle ne réécrit pas les identifiants
            // périmés qui ne figurent pas dans le jeu de modifications.
            if ($pending === []) {
                return parent::save($options);
            }

            if ($this->getConnection()->getDriverName() === 'pgsql') {
                $isolation = $this->getConnection()->selectOne('SHOW transaction_isolation');
                if ($isolation->transaction_isolation !== 'read committed') {
                    throw ValidationException::withMessages([
                        array_key_first($pending) => 'La modification d’identité exige une transaction READ COMMITTED. Veuillez recommencer dans un contexte compatible.',
                    ]);
                }
            }

            $stored = $this->newQuery()
                ->whereKey($this->getRawOriginal($this->getKeyName()))
                ->lockForUpdate()->firstOrFail();
            $changed = array_filter($pending, static function ($value, $field) use ($stored): bool {
                $previous = $stored->getAttribute($field);

                return ($value === null ? null : (string) $value)
                    !== ($previous === null ? null : (string) $previous);
            }, ARRAY_FILTER_USE_BOTH);

            if ($changed !== [] && $stored->hasAllocationHistory()) {
                throw ValidationException::withMessages(array_fill_keys(
                    array_keys($changed),
                    'Cette fiche est référencée par une allocation : son fournisseur, son produit et sa variante ne peuvent plus changer.'
                ));
            }

            return parent::save($options);
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
