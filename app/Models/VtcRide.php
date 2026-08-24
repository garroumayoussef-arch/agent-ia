<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prestation VTC — une ligne unique (contrairement à
 * PurchaseOrder/SalesOrder), sans aucune interaction avec le stock :
 * aucune référence à Product/ProductVariant/StockMovement.
 *
 * Architecture fiscale : réutilise intégralement TaxRate et
 * FiscalSetting (déjà construits à l'étape 4 / 5.1). Aucune nouvelle
 * abstraction fiscale partagée n'est créée ici — l'arithmétique
 * (base × taux / 100) reste volontairement locale à ce modèle, sur le
 * même principe que le projet duplique déjà cette même arithmétique
 * entre PurchaseOrder et SalesOrder plutôt que de la mutualiser
 * (décision explicite de l'étape 4 : ne jamais mélanger les logiques
 * fiscales de contextes différents). Une course n'ayant qu'une seule
 * "ligne" (elle-même), la logique de répartition au prorata entre
 * plusieurs lignes de PurchaseOrder/SalesOrder ne s'applique pas ici :
 * il n'y a rien à dupliquer de ce côté-là.
 */
class VtcRide extends Model
{
    protected $guarded = [];

    protected $casts = [
        'performed_at' => 'datetime',
        'confirmed_at' => 'datetime',
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

    protected static function booted(): void
    {
        static::creating(function (VtcRide $ride) {
            $ride->status ??= self::STATUS_DRAFT;
            $ride->user_id ??= auth()->id();
        });

        /*
         * Recalcule total_ht/tax_rate_id/tax_rate/tax_amount/total_ttc/
         * tax_status/legal_mention tant que la course est en brouillon.
         * Une fois confirmée, ce hook ne fait plus rien : $ride->status
         * est déjà passé à 'confirmed' en mémoire au moment où
         * markAsConfirmed() déclenche ce save() (fill() a lieu avant
         * l'événement `saving`), donc les valeurs calculées lors du
         * dernier enregistrement en brouillon restent telles quelles —
         * c'est ce qui fige l'historique fiscal, sans mécanisme
         * supplémentaire.
         *
         * `status` peut encore être NULL ici lors d'une création : dans
         * Eloquent, `saving` se déclenche AVANT `creating` (qui est ce
         * qui fixe status à 'draft' par défaut ci-dessus) — un statut
         * absent est donc traité comme brouillon, sans quoi aucune
         * nouvelle course ne verrait jamais ses montants calculés.
         */
        static::saving(function (VtcRide $ride) {
            if (! in_array($ride->status, [null, self::STATUS_DRAFT], true)) {
                return;
            }

            $taxRate = $ride->resolveTaxRate();

            if ($taxRate === null) {
                $ride->tax_rate_id = null;
                $ride->tax_rate = null;
                $ride->tax_status = self::TAX_STATUS_UNRESOLVED;
                $ride->legal_mention = null;
            } elseif ($taxRate->isExempt()) {
                $ride->tax_rate_id = $taxRate->id;
                $ride->tax_rate = null;
                $ride->tax_status = self::TAX_STATUS_EXEMPT;
                $ride->legal_mention = $taxRate->legal_mention;
            } else {
                $ride->tax_rate_id = $taxRate->id;
                $ride->tax_rate = (float) $taxRate->rate;
                $ride->tax_status = self::TAX_STATUS_TAXABLE;
                $ride->legal_mention = null;
            }

            $ride->total_ht = $ride->price_ht !== null
                ? max(0.0, round((float) $ride->price_ht - (float) $ride->discount_amount, 2))
                : null;

            if ($ride->total_ht === null || $ride->tax_status === self::TAX_STATUS_UNRESOLVED) {
                // Base inconnue, ou taux inconnu : TVA inconnue. Ne
                // jamais inventer une valeur, y compris 0.
                $ride->tax_amount = null;
                $ride->total_ttc = null;
            } elseif ($ride->tax_status === self::TAX_STATUS_EXEMPT) {
                // TVA connue comme nulle (exonération/franchise en
                // base) : 0 explicite, jamais confondu avec l'inconnu.
                $ride->tax_amount = 0.0;
                $ride->total_ttc = $ride->total_ht;
            } else {
                $ride->tax_amount = round($ride->total_ht * $ride->tax_rate / 100, 2);
                $ride->total_ttc = round($ride->total_ht + $ride->tax_amount, 2);
            }
        });

        /*
         * Une fois confirmée (ou annulée), une course est un historique
         * permanent : ses montants et sa qualification fiscale sont
         * figés, au même titre que product_id/quantity_ordered le sont
         * sur une ligne de PurchaseOrder/SalesOrder une fois la
         * commande sortie du brouillon. Un changement ultérieur de
         * TaxRate ou de FiscalSetting ne peut donc jamais l'atteindre :
         * ce hook bloque toute tentative de modification directe, et
         * `saving` ci-dessus a de toute façon cessé de recalculer quoi
         * que ce soit dès que le statut n'est plus 'draft'.
         */
        static::updating(function (VtcRide $ride) {
            /*
             * confirmed_at est un cas à part, vérifié INCONDITIONNELLEMENT
             * (pas seulement hors brouillon) : c'est justement l'appel
             * qui fait passer status à 'confirmed' qui la renseigne pour
             * la première fois (cf. markAsConfirmed()), donc la garder
             * dans la boucle ci-dessous — qui ne s'active qu'une fois
             * status déjà 'confirmed' — bloquerait la confirmation
             * elle-même. La règle réelle est : modifiable une seule fois
             * (de NULL vers une date), plus jamais ensuite.
             */
            if ($ride->isDirty('confirmed_at') && $ride->getOriginal('confirmed_at') !== null) {
                throw new \Exception(
                    "Impossible de modifier la date de confirmation d'une course."
                );
            }

            if ($ride->status === self::STATUS_DRAFT) {
                return;
            }

            foreach ([
                'price_ht', 'discount_amount', 'total_ht',
                'tax_rate_id', 'tax_rate', 'tax_amount', 'total_ttc',
                'tax_status', 'legal_mention',
            ] as $field) {
                if ($ride->isDirty($field)) {
                    throw new \Exception(
                        "Impossible de modifier les montants d'une course qui n'est plus en brouillon."
                    );
                }
            }
        });

        /*
         * Une fois confirmée, une course devient un historique
         * permanent : même logique de protection que
         * PurchaseOrder::deleting()/SalesOrder::deleting() une fois
         * qu'une réception/expédition a eu lieu.
         */
        static::deleting(function (VtcRide $ride) {
            if ($ride->status !== self::STATUS_DRAFT) {
                throw new \Exception(
                    'Impossible de supprimer une course qui n\'est plus en brouillon.'
                );
            }
        });
    }

    /**
     * Confirme une course en brouillon : chauffeur et véhicule
     * deviennent obligatoires à cet instant précis (pas avant — une
     * course peut être préparée sans eux), tous deux ACTIFS (étape
     * 5.10), et la qualification fiscale doit être résolue (pas de TVA
     * inconnue sur une course confirmée). Une fois confirmée, les
     * montants sont figés (cf. static::updating ci-dessus).
     *
     * is_active n'est vérifié qu'ICI, pas ailleurs : un brouillon
     * référençant un chauffeur/véhicule devenu inactif entre-temps
     * reste normalement consultable et modifiable (cf. VtcRideForm,
     * qui ne filtre volontairement pas ses select sur is_active pour
     * cette étape — seule la confirmation doit être bloquée). Aucun
     * effet rétroactif sur une course déjà confirmée : is_active n'est
     * qu'une porte d'entrée, jamais revérifiée après coup.
     *
     * Requêtes fraîches ($this->driver()->first(), pas l'accesseur de
     * relation $this->driver) — même précaution que resolveTaxRate().
     */
    public function markAsConfirmed(): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new \Exception('Seule une course en brouillon peut être confirmée.');
        }

        if (! $this->driver_id) {
            throw new \Exception('Un chauffeur doit être renseigné avant de confirmer cette course.');
        }

        if (! $this->driver()->first()?->is_active) {
            throw new \Exception('Le chauffeur assigné à cette course est inactif : impossible de confirmer.');
        }

        if (! $this->vehicle_id) {
            throw new \Exception('Un véhicule doit être renseigné avant de confirmer cette course.');
        }

        if (! $this->vehicle()->first()?->is_active) {
            throw new \Exception('Le véhicule assigné à cette course est inactif : impossible de confirmer.');
        }

        if ($this->total_ht === null) {
            throw new \Exception('Le prix HT de cette course doit être renseigné avant de la confirmer.');
        }

        if ($this->tax_status === self::TAX_STATUS_UNRESOLVED) {
            throw new \Exception(
                "Aucun taux de TVA n'a pu être résolu pour cette course : configurez le régime fiscal VTC (FiscalSetting) avant de la confirmer."
            );
        }

        $this->update([
            'status' => self::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Annule une course en brouillon. Contrairement à
     * PurchaseOrder::cancel()/SalesOrder::cancel() (autorisés aussi
     * depuis un statut intermédiaire type "ordered"/"confirmed"),
     * VtcRide n'a pas d'étape de réception/expédition à défaire : seule
     * une course encore en brouillon peut être annulée, jamais une
     * course déjà confirmée (cf. le schéma de statuts en tête de
     * classe). Une fois annulée, static::updating() ci-dessus fige les
     * montants exactement comme pour une course confirmée — étape 5.7.
     */
    public function cancel(): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new \Exception('Seule une course en brouillon peut être annulée.');
        }

        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    /**
     * Résout le taux de TVA applicable à cette course : explicite sur
     * la course > configuration fiscale VTC (FiscalSetting) > non
     * résolu. Ne retombe JAMAIS sur un taux par défaut "marchandises"
     * (TaxRate::is_default_sale) : une course sans régime VTC configuré
     * doit rester non résolue plutôt que d'être taxée par erreur au
     * taux des produits physiques.
     *
     * Requêtes fraîches (pas les accesseurs de relation), comme dans
     * PurchaseOrderItem/SalesOrderItem, pour ne rien mettre en cache
     * sur cette instance appelée depuis `saving`.
     */
    private function resolveTaxRate(): ?TaxRate
    {
        if ($this->tax_rate_id) {
            return $this->taxRate()->first();
        }

        return FiscalSetting::where('activity', FiscalSetting::ACTIVITY_VTC)
            ->first()
            ?->taxRate()
            ->first();
    }

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
