<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape T24 — ligne d'avoir, snapshot figé d'une InvoiceLine créditée.
 * Immuable au même titre que CreditNote (cf. booted() ci-dessous).
 *
 * La contrainte UNIQUE sur invoice_line_id (migration) est ce qui rend
 * le sur-crédit structurellement impossible — jamais deux
 * CreditNoteLine pour la même InvoiceLine, tous avoirs confondus.
 */
class CreditNoteLine extends Model
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
                "Une ligne d'avoir ne peut pas être modifiée après son émission."
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                "Une ligne d'avoir ne peut pas être supprimée après son émission."
            );
        });
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
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
