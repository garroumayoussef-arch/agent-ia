<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape T28 — enregistrement d'une facture fournisseur reçue.
 * Immuable après création (cf. booted() ci-dessous), comme Invoice
 * (T23) — mais sans numérotation ni génération : le numéro est celui
 * DU FOURNISSEUR (texte libre, jamais produit par Magarrou), et les
 * montants sont saisis directement (jamais recalculés depuis les
 * lignes du bon de commande — un fournisseur peut facturer un montant
 * différent : frais de port, ajustement de prix).
 *
 * Contrairement à Invoice/CreditNote, il n'existe volontairement
 * aucun point d'entrée statique de type generateFromX() : aucune
 * source à partir de laquelle dériver les données (un utilisateur
 * transcrit manuellement un document reçu), donc la création passe
 * simplement par le formulaire Filament standard (CreateSupplierInvoice).
 *
 * Aucune interaction avec Invoice/CreditNote/StockMovement/le
 * reporting T27 : relation additive uniquement vers Supplier et
 * PurchaseOrder (un seul PurchaseOrder par facture — décision 1).
 */
class SupplierInvoice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date',
        'total_ht' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierInvoice $invoice) {
            $invoice->user_id ??= auth()->id();

            $purchaseOrder = $invoice->purchaseOrder()->first();

            if ($purchaseOrder && in_array($purchaseOrder->status, [
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_CANCELLED,
            ], true)) {
                throw new \Exception(
                    "Impossible d'enregistrer une facture fournisseur pour un bon de commande en brouillon ou annulé."
                );
            }
        });

        /*
         * =================================================================
         * IMMUABILITÉ (décision 4 : "comme Invoice")
         * =================================================================
         */
        static::updating(function (): void {
            throw new \Exception(
                'Une facture fournisseur ne peut pas être modifiée après son enregistrement.'
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                'Une facture fournisseur ne peut pas être supprimée après son enregistrement.'
            );
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
