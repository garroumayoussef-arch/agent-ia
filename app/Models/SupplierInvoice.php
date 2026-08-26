<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /*
     * =================================================================
     * Étape T30 — suivi des paiements. Statut jamais stocké : toujours
     * recalculé depuis la somme réelle de SupplierInvoicePayment (cf.
     * paymentStatus() ci-dessous) — aucun champ à désynchroniser.
     * =================================================================
     */
    public const PAYMENT_STATUS_UNPAID = 'non_payee';

    public const PAYMENT_STATUS_PARTIAL = 'partiellement_payee';

    public const PAYMENT_STATUS_PAID = 'payee';

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

    /**
     * Étape T30 — paiements enregistrés contre cette facture (0, 1, ou
     * plusieurs si réglée en plusieurs fois). Relation additive en
     * lecture seule : ne crée aucune nouvelle écriture sur
     * SupplierInvoice, son immuabilité (T28) reste entièrement
     * préservée.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierInvoicePayment::class);
    }

    /**
     * Toujours une requête fraîche (jamais mise en cache sur
     * l'instance) : garantit que le montant reflète l'état réel de la
     * base à l'instant de l'appel, y compris juste après un
     * enregistrement concurrent ailleurs (cf. SupplierInvoicePayment::recordFor()).
     * Somme calculée côté base sur la colonne decimal(10,2), jamais en
     * additionnant manuellement des valeurs PHP récupérées une par une.
     */
    public function amountPaid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    public function amountRemaining(): float
    {
        return round((float) $this->total_ttc - $this->amountPaid(), 2);
    }

    /**
     * Source de vérité unique du statut de paiement : jamais un champ
     * stocké, toujours recalculé depuis amountPaid() ci-dessus.
     */
    public function paymentStatus(): string
    {
        $paid = $this->amountPaid();
        $total = round((float) $this->total_ttc, 2);

        return match (true) {
            $paid <= 0 => self::PAYMENT_STATUS_UNPAID,
            $paid >= $total => self::PAYMENT_STATUS_PAID,
            default => self::PAYMENT_STATUS_PARTIAL,
        };
    }
}
