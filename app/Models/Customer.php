<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Étape T23 — distingue un client particulier (B2C) d'un client
    // professionnel (B2B), nécessaire pour déterminer les mentions
    // légales applicables à une facture (cf. Invoice::generateFromSalesOrder()).
    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_BUSINESS = 'business';

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function vtcRides(): HasMany
    {
        return $this->hasMany(VtcRide::class);
    }
}
