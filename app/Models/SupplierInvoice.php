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

    /**
     * Chantier "avoir fournisseur" (réconciliation, D2 de l'analyse
     * sur Invoice, répliquée à l'identique) — facture dont le montant
     * net (après avoirs) est totalement couvert par un ou plusieurs
     * avoirs, SANS aucun paiement réel. Distincte de
     * PAYMENT_STATUS_PAID : "payée" signifie toujours un décaissement
     * réel de Magarrou vers le fournisseur, jamais un simple solde net
     * nul obtenu par avoir (cf. paymentStatus() ci-dessous).
     */
    public const PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE = 'soldee_par_avoir';

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

    /**
     * Chantier "avoir fournisseur" — avoirs reçus contre cette facture
     * (0, 1, ou plusieurs si créditée par avoirs partiels successifs).
     * Relation additive en lecture seule : ne crée aucune nouvelle
     * écriture sur SupplierInvoice, son immuabilité (T28) reste
     * entièrement préservée.
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(SupplierCreditNote::class);
    }

    /**
     * Chantier "avoir fournisseur" (réconciliation) — somme des avoirs
     * (SupplierCreditNote) reçus contre cette facture. Même convention
     * qu'amountPaid() ci-dessus : toujours une requête fraîche, jamais
     * mise en cache sur l'instance, somme calculée côté base sur la
     * colonne decimal(10,2).
     *
     * Ne peut structurellement jamais dépasser total_ttc :
     * SupplierCreditNote::recordFor() plafonne déjà le cumul des
     * avoirs au total_ttc de CETTE facture (T32).
     */
    public function creditedAmount(): float
    {
        return round((float) $this->creditNotes()->sum('total_ttc'), 2);
    }

    /**
     * Montant net réellement dû après avoirs, avant déduction des
     * paiements. Jamais négatif (cf. creditedAmount() ci-dessus).
     * Privé : détail de calcul interne à amountRemaining()/
     * creditBalance()/paymentStatus(), même principe qu'Invoice::netTotalDue()
     * (chantier de réconciliation avoirs/paiements, T24/T31).
     */
    private function netTotalDue(): float
    {
        return round((float) $this->total_ttc - $this->creditedAmount(), 2);
    }

    /**
     * Reste dû = total_ttc − avoirs − paiements. Plafonné à 0 : un
     * éventuel excédent (paiements déjà versés dépassant le nouveau
     * montant net après un avoir reçu après-coup) n'est jamais affiché
     * ici en négatif, cf. creditBalance() ci-dessous qui l'expose
     * séparément — jamais fusionné avec ce montant. Même formule
     * qu'Invoice::amountRemaining() (réconciliation avoirs/paiements).
     */
    public function amountRemaining(): float
    {
        return max(0.0, round($this->netTotalDue() - $this->amountPaid(), 2));
    }

    /**
     * Solde créditeur : montant que le fournisseur doit à Magarrou
     * lorsque les paiements déjà versés dépassent le montant net
     * réellement dû après avoirs (ex. avoir reçu après un paiement déjà
     * intégral). Toujours ≥ 0, jamais mélangé à amountRemaining() : une
     * information distincte (une créance sur le fournisseur), jamais
     * une simple valeur négative de "reste dû". Même principe
     * qu'Invoice::creditBalance().
     */
    public function creditBalance(): float
    {
        return max(0.0, round($this->amountPaid() - $this->netTotalDue(), 2));
    }

    /**
     * Source de vérité unique du statut de paiement : jamais un champ
     * stocké, toujours recalculé depuis amountPaid()/creditedAmount()
     * ci-dessus.
     *
     * Statut à 4 valeurs (chantier "avoir fournisseur", réconciliation,
     * même logique qu'Invoice::paymentStatus()) : "payée" exige un
     * décaissement réel (paid > 0 et couvrant le net) : une facture
     * intégralement soldée par avoir SANS aucun paiement obtient le
     * statut dédié PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE, jamais
     * confondue avec PAYMENT_STATUS_PAID. Le garde `$credited > 0`
     * évite qu'une facture à 0 € sans aucun avoir (paid=0, net=0) ne
     * soit prise à tort pour "soldée par avoir" — comportement
     * inchangé pour ce cas marginal (retombe sur PAYMENT_STATUS_UNPAID,
     * comme avant ce chantier).
     */
    public function paymentStatus(): string
    {
        $paid = $this->amountPaid();
        $credited = $this->creditedAmount();
        $netTotal = round((float) $this->total_ttc - $credited, 2);

        if ($paid <= 0 && $netTotal <= 0 && $credited > 0) {
            return self::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE;
        }

        return match (true) {
            $paid <= 0 => self::PAYMENT_STATUS_UNPAID,
            $paid >= $netTotal => self::PAYMENT_STATUS_PAID,
            default => self::PAYMENT_STATUS_PARTIAL,
        };
    }
}
