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

        $invoice = DB::transaction(function () use ($order, $customer, $isBusiness, $companySettings) {
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

        // Chantier "Notifications & communication" V1 (D1/D5/D8/D12,
        // validés) — dispatché ICI, jamais à l'intérieur de la fermeture
        // ci-dessus : DB::transaction() a déjà retourné, donc déjà
        // committée, à ce point d'exécution (défense en profondeur
        // supplémentaire : InvoiceIssuedMail implémente aussi
        // ShouldQueueAfterCommit). NotificationLog::reserve() ne peut
        // jamais faire échouer generateFromSalesOrder() elle-même : un
        // événement déjà notifié retourne null, jamais une exception.
        static::dispatchInvoiceIssuedNotification($invoice, $customer);

        return $invoice;
    }

    /**
     * Chantier "Notifications & communication" V1 (D1/D2/D4, validés) —
     * réutilisée par generateFromSalesOrder() ci-dessus ET
     * generateFromVtcRide() ci-dessous : un seul point d'émission de cet
     * événement, jamais deux définitions susceptibles de diverger.
     * $customer est déjà chargé (jamais une seconde requête) — email
     * lu depuis la relation CURRENTE (jamais un snapshot, contrairement
     * aux champs légaux de la facture elle-même) : la notification doit
     * atteindre l'adresse à jour du client, pas une adresse historique.
     */
    private static function dispatchInvoiceIssuedNotification(self $invoice, Customer $customer): void
    {
        $log = NotificationLog::reserve($invoice, 'invoice_issued', 'email', $customer->email);

        if ($log !== null && $log->status === NotificationLog::STATUS_QUEUED) {
            \Illuminate\Support\Facades\Mail::to($customer->email)
                ->queue(new \App\Mail\InvoiceIssuedMail($invoice, $log->id));
        }
    }

    /**
     * Chantier "facturation légale VTC" (D1/D3/D5/D6, validés) — génère
     * une facture à partir d'une VtcRide confirmée. Miroir exact de
     * generateFromSalesOrder() ci-dessus (mêmes garanties : immuabilité,
     * numérotation verrouillée, SIREN B2B, régime TVA jamais supposé),
     * jamais fusionnée avec elle : chaque origine garde son propre point
     * d'entrée explicite, comme le reste de ce projet distingue déjà
     * SalesOrder/PurchaseOrder plutôt que de les unifier artificiellement.
     *
     * D5 (validé) — statut confirmed strictement requis, garde
     * anti-doublon à plusieurs niveaux :
     * 1. pré-vérification applicative avant toute transaction (rejet
     *    rapide, cas non concurrent très largement majoritaire) ;
     * 2. re-vérification IDENTIQUE À L'INTÉRIEUR de la transaction,
     *    avant toute écriture — ferme la fenêtre de course avec un
     *    appel strictement concurrent sur la même course ;
     * 3. contrainte UNIQUE en base sur invoices.vtc_ride_id (migration
     *    dédiée), rempart final même en cas de contournement des deux
     *    premiers niveaux — une violation (SQLSTATE 23000) est
     *    interceptée et traduite dans le même message métier, jamais
     *    laissée remonter brute à l'appelant.
     *
     * D2 (validé) — numérotation sur une série dédiée (préfixe
     * vtc_invoice_number_prefix), strictement indépendante de la série
     * vente (cf. InvoiceSequence, compteur désormais indexé par
     * year+prefix).
     *
     * D3 (validé) — une InvoiceLine UNIQUE porte les montants de la
     * course (product_id/product_variant_id null, quantity = 1) :
     * c'est ce qui permet à CreditNote::generateFromInvoice() (T24) de
     * fonctionner sur une facture VTC SANS AUCUNE MODIFICATION — aucune
     * logique d'avoir dupliquée ici.
     *
     * D6 (validé) — client et SIREN B2B contrôlés ICI, au moment de la
     * facturation, jamais à la confirmation de la course
     * (VtcRide::markAsConfirmed() reste inchangée) — même principe que
     * generateFromSalesOrder() ci-dessus.
     */
    public static function generateFromVtcRide(VtcRide $ride): self
    {
        if ($ride->status !== VtcRide::STATUS_CONFIRMED) {
            throw new \Exception(
                'Seule une course confirmée peut être facturée.'
            );
        }

        if (static::where('vtc_ride_id', $ride->id)->exists()) {
            throw new \Exception('Une facture a déjà été émise pour cette course.');
        }

        $customer = $ride->customer;

        if (! $customer) {
            throw new \Exception("Cette course n'a pas de client associé : impossible de générer une facture.");
        }

        $isBusiness = $customer->customer_type === Customer::TYPE_BUSINESS;

        if ($isBusiness && blank($customer->siren)) {
            throw new \Exception(
                'Le SIREN du client professionnel doit être renseigné avant de générer la facture.'
            );
        }

        $companySettings = CompanySettings::current();
        $companySettings->assertReadyForInvoicing();

        try {
            $invoice = DB::transaction(function () use ($ride, $customer, $isBusiness, $companySettings) {
                // Niveau 2 (D5, validé) — revérification IDENTIQUE au
                // pré-contrôle ci-dessus, relue fraîchement À L'INTÉRIEUR
                // de la transaction, avant toute écriture : ferme la
                // fenêtre de course avec un appel strictement concurrent
                // sur la même course (jamais confiance dans le résultat
                // lu avant l'ouverture de la transaction).
                if (static::where('vtc_ride_id', $ride->id)->exists()) {
                    throw new \Exception('Une facture a déjà été émise pour cette course.');
                }

                $number = InvoiceSequence::nextNumber(
                    (int) now()->format('Y'),
                    $companySettings->vtc_invoice_number_prefix ?: 'FV',
                );

                // Date de vente/prestation : performed_at (date réelle où
                // la course a eu lieu) en priorité — donnée plus fiable
                // que confirmed_at (simple horodatage administratif de
                // confirmation). Repli sur confirmed_at si performed_at
                // n'a jamais été renseigné (champ nullable sur VtcRide),
                // puis sur aujourd'hui dans le seul cas — normalement
                // impossible pour une course confirmed — où aucune des
                // deux ne serait disponible.
                $saleCompletedAt = $ride->performed_at ?? $ride->confirmed_at ?? now();

                $invoice = static::create([
                    'vtc_ride_id' => $ride->id,
                    'number' => $number,
                    'issued_at' => now()->toDateString(),
                    'sale_completed_at' => \Illuminate\Support\Carbon::parse($saleCompletedAt)->toDateString(),
                    // Catégorie d'opération — 'prestation', jamais 'vente'
                    // (valeur par défaut du champ, réservée à l'origine
                    // SalesOrder) : ce champ existe précisément pour
                    // distinguer les deux catégories (cf. sa
                    // documentation sur la migration T23), jamais exploité
                    // jusqu'ici faute d'une seconde origine.
                    'operation_category' => 'prestation',
                    'transaction_type' => static::resolveTransactionType($customer, $companySettings, $isBusiness),
                    'vtc_ride_reference' => $ride->reference,

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
                    // Aucune adresse de livraison distincte n'existe pour
                    // une course VTC (pas de notion de livraison) :
                    // jamais une valeur inventée à la place de NULL.
                    'delivery_address_snapshot' => null,

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

                    'total_ht' => $ride->total_ht,
                    'discount_amount' => $ride->discount_amount,
                    'tax_amount' => $ride->tax_amount,
                    'total_ttc' => $ride->total_ttc,

                    'user_id' => auth()->id(),
                    'status' => self::STATUS_ISSUED,
                ]);

                // D3 (validé) — ligne unique, product_id/product_variant_id
                // null (aucun produit physique concerné), quantity = 1 :
                // c'est cette ligne qui rend l'avoir (CreditNote, T24)
                // possible sur une facture VTC sans aucune modification de
                // CreditNote/CreditNoteLine.
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => null,
                    'product_variant_id' => null,
                    'product_name' => "Course VTC — {$ride->reference}",
                    'variant_description' => null,
                    'quantity' => 1,
                    'unit_price_ht' => $ride->total_ht,
                    'subtotal_ht' => $ride->total_ht,
                    'tax_rate' => $ride->tax_rate,
                    'tax_amount' => $ride->tax_amount,
                    'total_ttc' => $ride->total_ttc,
                ]);

                return $invoice;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Niveau 3 (D5, validé), dernier recours — la contrainte
            // UNIQUE sur vtc_ride_id peut encore lever une QueryException
            // ici (SQLSTATE 23000) si, malgré le niveau 2 ci-dessus, une
            // autre transaction a committé une facture concurrente entre
            // temps. Rejet métier DÉFINITIF (jamais retenté, aucune
            // situation de contention transitoire légitime ici,
            // contrairement à InvoiceSequence::nextNumber() qui gère sa
            // propre contention séparément) — même message que les
            // niveaux 1/2, jamais une exception technique brute
            // remontée à l'appelant.
            if ($e->getCode() === '23000') {
                throw new \Exception('Une facture a déjà été émise pour cette course.');
            }

            throw $e;
        }

        // Chantier "Notifications & communication" V1 (D1/D5/D8/D12,
        // validés) — même point d'émission unique que
        // generateFromSalesOrder() (cf. dispatchInvoiceIssuedNotification()
        // ci-dessus), atteint UNIQUEMENT sur le chemin de succès (jamais
        // depuis le catch()) : une facture VTC rejetée (doublon, SIREN...)
        // ne déclenche jamais de notification.
        static::dispatchInvoiceIssuedNotification($invoice, $customer);

        return $invoice;
    }

    /**
     * Chantier "facturation légale VTC" (D4, validé) — libellé d'origine
     * calculé, jamais un champ stocké : lit salesOrder_reference ou
     * vtc_ride_reference selon celle des deux origines qui est
     * renseignée (D1 : exactement une des deux, jamais les deux).
     * Réutilisable partout où l'origine doit être affichée (table,
     * infolist, PDF) — un seul endroit de vérité pour ce libellé.
     */
    public function originLabel(): string
    {
        if ($this->sales_order_id !== null) {
            return "Commande {$this->sales_order_reference}";
        }

        return "Course VTC {$this->vtc_ride_reference}";
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

    /**
     * Chantier "facturation légale VTC" (D1, validé) — seconde origine
     * possible d'une facture, mutuellement exclusive avec salesOrder()
     * ci-dessus (exactement l'une des deux relations résout un
     * enregistrement, jamais les deux, jamais aucune).
     */
    public function vtcRide(): BelongsTo
    {
        return $this->belongsTo(VtcRide::class);
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

    /**
     * Étape T24 — avoirs émis sur cette facture (0, 1, ou plusieurs si
     * créditée par avoirs partiels successifs). Relation additive en
     * lecture seule : ne crée aucune nouvelle écriture sur Invoice,
     * l'immuabilité de la facture reste entièrement préservée.
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /*
     * =================================================================
     * Étape T31 — suivi des paiements clients. Statut jamais stocké :
     * toujours recalculé depuis la somme réelle de InvoicePayment (cf.
     * paymentStatus() ci-dessous) — aucun champ à désynchroniser. Report
     * exact du mécanisme déjà validé sur SupplierInvoice (T30).
     *
     * Chantier "réconciliation avoirs" (D1-D7, validés) — le solde dû
     * et le statut tiennent désormais compte des avoirs (CreditNote,
     * T24) en plus des paiements, jamais l'un sans l'autre. Formule
     * unique (D1), répliquée à l'identique (D6, aucune abstraction
     * nouvelle) dans InvoicesTable (filtre payment_status) et
     * CommercialOverview (tuile "Restant dû").
     * =================================================================
     */
    public const PAYMENT_STATUS_UNPAID = 'non_payee';

    public const PAYMENT_STATUS_PARTIAL = 'partiellement_payee';

    public const PAYMENT_STATUS_PAID = 'payee';

    /**
     * D2 (validé) — facture dont le montant net (après avoirs) est
     * totalement couvert par un ou plusieurs avoirs, SANS aucun
     * paiement réel. Distincte de PAYMENT_STATUS_PAID : "payée"
     * signifie toujours un encaissement réel, jamais un simple solde
     * net nul obtenu par avoir (cf. paymentStatus() ci-dessous).
     */
    public const PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE = 'soldee_par_avoir';

    /**
     * Étape T31 — paiements enregistrés contre cette facture (0, 1, ou
     * plusieurs si réglée en plusieurs fois). Relation additive en
     * lecture seule : ne crée aucune nouvelle écriture sur Invoice, son
     * immuabilité (T23) reste entièrement préservée.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /**
     * Toujours une requête fraîche (jamais mise en cache sur
     * l'instance) : garantit que le montant reflète l'état réel de la
     * base à l'instant de l'appel, y compris juste après un
     * enregistrement concurrent ailleurs (cf. InvoicePayment::recordFor()).
     * Somme calculée côté base sur la colonne decimal(10,2), jamais en
     * additionnant manuellement des valeurs PHP récupérées une par une.
     */
    public function amountPaid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    /**
     * D1 (validé) — somme des avoirs (CreditNote, T24) émis contre
     * cette facture. Même convention qu'amountPaid() ci-dessus :
     * toujours une requête fraîche, jamais mise en cache sur
     * l'instance, somme calculée côté base sur la colonne
     * decimal(10,2).
     *
     * Ne peut structurellement jamais dépasser total_ttc : un avoir ne
     * porte jamais que sur un sous-ensemble des lignes de CETTE
     * facture (CreditNote::generateFromInvoice()), et la contrainte
     * UNIQUE sur credit_note_lines.invoice_line_id interdit qu'une
     * même ligne soit créditée deux fois, tous avoirs confondus — donc
     * aucun risque de double comptage même avec plusieurs avoirs
     * successifs (cf. CreditNoteLine).
     */
    public function creditedAmount(): float
    {
        return round((float) $this->creditNotes()->sum('total_ttc'), 2);
    }

    /**
     * Montant net réellement dû après avoirs, avant déduction des
     * paiements. Jamais négatif (cf. creditedAmount() ci-dessus).
     * Privé : détail de calcul interne à amountRemaining()/
     * creditBalance()/paymentStatus(), jamais exposé tel quel (D6 —
     * aucune nouvelle API publique au-delà de ce que D1-D5 exigent).
     */
    private function netTotalDue(): float
    {
        return round((float) $this->total_ttc - $this->creditedAmount(), 2);
    }

    /**
     * D1 (validé) — reste dû = total_ttc − avoirs − paiements. D3
     * (validé) — plafonné à 0 : un éventuel excédent (paiements déjà
     * encaissés dépassant le nouveau montant net après un avoir émis
     * après-coup) n'est jamais affiché ici en négatif, cf.
     * creditBalance() ci-dessous qui l'expose séparément — jamais
     * fusionné avec ce montant.
     */
    public function amountRemaining(): float
    {
        return max(0.0, round($this->netTotalDue() - $this->amountPaid(), 2));
    }

    /**
     * D3 (validé) — solde créditeur : montant que Magarrou doit au
     * client lorsque les paiements déjà encaissés dépassent le montant
     * net réellement dû après avoirs (ex. avoir émis après un paiement
     * déjà intégral). Toujours ≥ 0, jamais mélangé à
     * amountRemaining() : une information distincte (une dette envers
     * le client), jamais une simple valeur négative de "reste dû".
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
     * D2 (validé) — statut à 4 valeurs. "payée" exige un encaissement
     * réel (paid > 0 et couvrant le net) : une facture intégralement
     * soldée par avoir SANS aucun paiement obtient le statut dédié
     * PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE, jamais confondue avec
     * PAYMENT_STATUS_PAID. Le garde `$credited > 0` évite qu'une
     * facture à 0 € sans aucun avoir (paid=0, net=0) ne soit prise à
     * tort pour "soldée par avoir" — comportement inchangé pour ce cas
     * marginal (retombe sur PAYMENT_STATUS_UNPAID, comme avant ce
     * chantier).
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
