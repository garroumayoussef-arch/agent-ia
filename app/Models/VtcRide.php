<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prestation VTC — une ligne unique (contrairement à
 * PurchaseOrder/SalesOrder), sans aucune interaction avec le stock :
 * aucune référence à Product/ProductVariant/StockMovement.
 *
 * La logique métier (résolution du taux, calculs HT/TVA/TTC, gel après
 * confirmation, obligation driver/vehicle à la confirmation) est
 * volontairement absente à ce stade (étape 1 = migrations + modèles +
 * relations uniquement) — elle sera ajoutée à l'étape 2.
 */
class VtcRide extends Model
{
    protected $guarded = [];

    protected $casts = [
        'performed_at' => 'datetime',
        'price_ht' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_ht' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    /*
     * =============================================================
     * STATUTS
     * =============================================================
     *
     * draft --confirm--> confirmed, ou cancelled (depuis draft
     * uniquement — pas de logique de réception/expédition à annuler
     * ici, contrairement à PurchaseOrder/SalesOrder).
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    /*
     * =============================================================
     * STATUT FISCAL
     * =============================================================
     *
     * Redondant par construction avec tax_rate_id/tax_rate (gardé en
     * clair pour la lisibilité/le filtrage), mais ne remplace jamais
     * la distinction NULL (inconnu) vs 0.00 (connu, nul) sur
     * tax_amount :
     * - TAX_STATUS_TAXABLE   : tax_rate_id + tax_rate renseignés.
     * - TAX_STATUS_EXEMPT    : tax_rate_id renseigné, tax_rate NULL.
     * - TAX_STATUS_UNRESOLVED: tax_rate_id NULL (aucun régime/taux
     *   configuré) — tax_amount reste alors NULL, jamais 0.
     */
    public const TAX_STATUS_TAXABLE = 'taxable';

    public const TAX_STATUS_EXEMPT = 'exempt';

    public const TAX_STATUS_UNRESOLVED = 'unresolved';

    /*
     * =============================================================
     * RELATIONS
     * =============================================================
     */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
