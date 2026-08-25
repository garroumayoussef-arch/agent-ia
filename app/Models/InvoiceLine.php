<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Étape T23 — ligne de facture, snapshot figé d'une SalesOrderItem au
 * moment de l'émission. Immuable au même titre que Invoice (cf.
 * booted() ci-dessous) : jamais éditable ni supprimable après
 * création, y compris pour un admin.
 */
class InvoiceLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_ht' => 'decimal:2',
        'subtotal_ht' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \Exception(
                "Une ligne de facture ne peut pas être modifiée après son émission."
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                "Une ligne de facture ne peut pas être supprimée après son émission."
            );
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * Étape T24 — au plus une CreditNoteLine par InvoiceLine, garanti
     * par la contrainte UNIQUE sur credit_note_lines.invoice_line_id
     * (empêche structurellement le sur-crédit). Relation additive en
     * lecture seule.
     */
    public function creditNoteLine(): HasOne
    {
        return $this->hasOne(CreditNoteLine::class);
    }
}
