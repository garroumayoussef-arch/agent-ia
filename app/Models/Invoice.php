<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Étape T23 — facture légale française (SASU), B2C + B2B, immuable
 * après émission. Point d'entrée UNIQUE de création :
 * generateFromSalesOrder() — même convention que StockTransfer::execute()
 * dans ce projet (méthode statique sur le modèle plutôt qu'une classe
 * Service séparée, aucun dossier Services n'existant dans ce projet).
 *
 * Toutes les données affichables (vendeur, acheteur, régime TVA,
 * montants, lignes) sont SNAPSHOTÉES sur cette table et sur
 * InvoiceLine au moment de l'émission : une facture ne dépend plus
 * JAMAIS de SalesOrder/Customer/CompanySettings/Product après sa
 * création — condition nécessaire à la fois pour l'immuabilité légale
 * (D5) et pour que le PDF soit régénérable à l'identique (contrainte
 * T23 point 9).
 */
class Invoice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'issued_at' => 'date',
        'sale_completed_at' => 'date',
        'seller_share_capital' => 'decimal:2',
        'recovery_indemnity_amount_snapshot' => 'decimal:2',
        'total_ht' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    public const STATUS_ISSUED = 'issued';

    /*
     * =================================================================
     * IMMUABILITÉ (contrainte T23 impérative n°5)
     * =================================================================
     */

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \Exception(
                "Une facture ne peut pas être modifiée après son émission. Utilisez un avoir (hors périmètre V1) pour toute correction."
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                "Une facture ne peut pas être supprimée après son émission."
            );
        });
    }

    /*
     * =================================================================
     * GÉNÉRATION (point d'entrée unique)
     * =================================================================
     */

    /**
     * Génère une facture à partir d'une SalesOrder intégralement
     * expédiée. Jamais confiance dans une valeur venue de l'appelant :
     * toutes les données sont relues fraîchement depuis SalesOrder/
     * Customer/CompanySettings au moment de l'appel.
     *
     * Règles impératives (D1-D8 de l'analyse validée) :
     * - statut SHIPPED strictement requis (D2/contrainte 7 — jamais une
     *   commande partiellement expédiée) ;
     * - une seule facture par SalesOrder ;
     * - CompanySettings doit être complet (assertReadyForInvoicing(),
     *   D5/contrainte 4) ;
     * - un client professionnel (customer_type = business) sans SIREN
     *   renseigné bloque la génération (D1/contrainte 8) ;
     * - numérotation verrouillée (InvoiceSequence::nextNumber(), D6/
     *   contrainte 6) ;
     * - date de vente/prestation = dernière expédition liée à la
     *   commande (D3, donnée déjà existante et fiable — jamais une date
     *   arbitraire).
     */
    public static function generateFromSalesOrder(SalesOrder $order): self
    {
        if ($order->status !== SalesOrder::STATUS_SHIPPED) {
            throw new \Exception(
                'Seule une commande intégralement expédiée peut être facturée (aucune facturation partielle en V1).'
            );
        }

        if (static::where('sales_order_id', $order->id)->exists()) {
            throw new \Exception('Une facture a déjà été émise pour cette commande.');
        }

        $customer = $order->customer;

        if (! $customer) {
            throw new \Exception("Cette commande n'a pas de client associé : impossible de générer une facture.");
        }

        $isBusiness = $customer->customer_type === Customer::TYPE_BUSINESS;

        if ($isBusiness && blank($customer->siren)) {
            throw new \Exception(
                'Le SIREN du client professionnel doit être renseigné avant de générer la facture.'
            );
        }

        $companySettings = CompanySettings::current();
        $companySettings->assertReadyForInvoicing();

        return DB::transaction(function () use ($order, $customer, $isBusiness, $companySettings) {
            $number = InvoiceSequence::nextNumber(
                (int) now()->format('Y'),
                $companySettings->invoice_number_prefix ?: 'FA',
            );

            $saleCompletedAt = StockMovement::where('sales_order_id', $order->id)->max('created_at');

            $invoice = static::create([
                'sales_order_id' => $order->id,
                'number' => $number,
                'issued_at' => now()->toDateString(),
                // D3 — dernière expédition liée à la commande : donnée
                // métier déjà existante et fiable (cf. analyse validée),
                // jamais une date arbitraire. Repli sur aujourd'hui dans
                // le seul cas — normalement impossible pour une commande
                // SHIPPED — où aucun mouvement ne serait rattaché.
                'sale_completed_at' => $saleCompletedAt
                    ? \Illuminate\Support\Carbon::parse($saleCompletedAt)->toDateString()
                    : now()->toDateString(),
                'operation_category' => 'vente',
                'transaction_type' => static::resolveTransactionType($customer, $companySettings, $isBusiness),
                'sales_order_reference' => $order->reference,

                'customer_id' => $customer->id,
                'customer_type' => $customer->customer_type,
                'customer_name' => $customer->name,
                'customer_company' => $customer->company,
                'customer_address' => $customer->address,
                'customer_postal_code' => $customer->postal_code,
                'customer_city' => $customer->city,
                'customer_country' => $customer->country,
                'customer_siren' => $customer->siren,
                'customer_vat_number' => $customer->vat_number,
                // Aucune adresse de livraison distincte de l'adresse
                // client n'existe nulle part dans ce projet (vérifié) :
                // repli sur l'adresse client tant qu'aucune autre source
                // n'existe.
                'delivery_address_snapshot' => $customer->address,

                'seller_legal_name' => $companySettings->legal_name,
                'seller_legal_form' => $companySettings->legal_form,
                'seller_share_capital' => $companySettings->share_capital,
                'seller_address' => $companySettings->address,
                'seller_postal_code' => $companySettings->postal_code,
                'seller_city' => $companySettings->city,
                'seller_country' => $companySettings->country,
                'seller_siren' => $companySettings->siren,
                'seller_siret' => $companySettings->siret,
                'seller_rcs_city' => $companySettings->rcs_city,
                'seller_vat_number' => $companySettings->vat_number,

                'vat_regime_snapshot' => $companySettings->vat_regime,
                'vat_exemption_mention_snapshot' => $companySettings->vat_exemption_mention,
                'vat_payment_option_snapshot' => $companySettings->vat_payment_option,

                'payment_terms_snapshot' => $companySettings->payment_terms_text,
                'discount_terms_snapshot' => $companySettings->discount_terms_text,
                'late_penalty_snapshot' => $companySettings->late_penalty_text,
                'recovery_indemnity_amount_snapshot' => $companySettings->recovery_indemnity_amount,

                'total_ht' => $order->total,
                'discount_amount' => $order->discount_amount,
                'tax_amount' => $order->tax_amount,
                'total_ttc' => $order->total_ttc,

                'user_id' => auth()->id(),
                'status' => self::STATUS_ISSUED,
            ]);

            foreach ($order->items as $item) {
                $variant = $item->productVariant;

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name' => $item->product?->nom ?? 'Produit supprimé',
                    'variant_description' => $variant
                        ? implode(' / ', array_filter([$variant->size, $variant->color, $variant->sku]))
                        : null,
                    'quantity' => $item->quantity_ordered,
                    'unit_price_ht' => $item->unit_price,
                    'subtotal_ht' => $item->subtotal,
                    'tax_rate' => $item->tax_rate,
                    'tax_amount' => $item->tax_amount,
                    'total_ttc' => ($item->subtotal !== null)
                        ? round((float) $item->subtotal + (float) ($item->tax_amount ?? 0), 2)
                        : null,
                ]);
            }

            return $invoice;
        });
    }

    /**
     * Classification informative pour un futur e-reporting (préparation
     * facturation électronique, hors périmètre d'intégration V1) —
     * n'affecte AUCUNE mention légale affichée sur le PDF, celles-ci ne
     * dépendent que du régime TVA snapshoté ci-dessus.
     *
     * Simplification V1 assumée : ne distingue pas les pays hors Union
     * européenne (tout pays différent du pays du vendeur est traité
     * comme "intracommunautaire") — suffisant pour classer une
     * opération, pas pour en déduire une mention fiscale automatique.
     */
    private static function resolveTransactionType(Customer $customer, CompanySettings $settings, bool $isBusiness): string
    {
        if (! $isBusiness) {
            return 'b2c_domestic';
        }

        $sameCountry = $customer->country && $settings->country
            && strcasecmp($customer->country, $settings->country) === 0;

        return $sameCountry ? 'b2b_domestic' : 'b2b_intracommunity';
    }

    /*
     * =================================================================
     * RELATIONS
     * =================================================================
     */

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
