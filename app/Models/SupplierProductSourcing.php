<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
