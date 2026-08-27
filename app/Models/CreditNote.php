<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Étape T24 — avoir/note de crédit, immuable après émission. Point
 * d'entrée UNIQUE de création : generateFromInvoice() — même
 * convention que Invoice::generateFromSalesOrder() (T23) et
 * StockTransfer::execute() (méthode statique sur le modèle, pas de
 * classe Service séparée, aucun dossier Services dans ce projet).
 *
 * D2 (validée) — les blocs vendeur/acheteur sont COPIÉS depuis
 * l'Invoice au moment de l'émission de l'avoir, JAMAIS relus depuis
 * CompanySettings::current()/Customer (qui pourraient avoir changé
 * depuis l'émission de la facture) : une seule source de vérité pour
 * ces données (l'Invoice déjà figée), jamais une seconde lecture
 * indépendante.
 */
class CreditNote extends Model
{
    protected $guarded = [];

    /*
     * =================================================================
     * Correctif de concurrence (chantier A, validé) — voir
     * generateFromInvoice() ci-dessous.
     * =================================================================
     */
    private const MAX_ATTEMPTS = 20;

    protected $casts = [
        'issued_at' => 'date',
        'invoice_issued_at_reference' => 'date',
        'seller_share_capital' => 'decimal:2',
        'total_ht' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    public const SCOPE_TOTAL = 'total';

    public const SCOPE_PARTIAL = 'partial';

    public const SETTLEMENT_REFUND = 'refund';

    public const SETTLEMENT_FUTURE_INVOICE = 'future_invoice';

    public const STATUS_ISSUED = 'issued';

    /*
     * =================================================================
     * IMMUABILITÉ (contrainte T24 impérative)
     * =================================================================
     */

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \Exception(
                'Un avoir ne peut pas être modifié après son émission.'
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                'Un avoir ne peut pas être supprimé après son émission.'
            );
        });
    }

    /*
     * =================================================================
     * GÉNÉRATION (point d'entrée unique)
     * =================================================================
     */

    /**
     * Lignes de la facture PAS ENCORE créditées par un avoir existant —
     * calculé à la demande (jamais un champ stocké, pour ne jamais
     * pouvoir se désynchroniser). Réutilisé à la fois pour proposer les
     * options des actions Filament (confort d'UI) et comme première
     * barrière de generateFromInvoice() ci-dessous.
     *
     * @return Collection<int, InvoiceLine>
     */
    public static function creditableLinesFor(Invoice $invoice): Collection
    {
        $allLines = $invoice->lines()->get();

        $alreadyCreditedLineIds = CreditNoteLine::query()
            ->whereIn('invoice_line_id', $allLines->pluck('id'))
            ->pluck('invoice_line_id');

        return $allLines->whereNotIn('id', $alreadyCreditedLineIds)->values();
    }

    /**
     * Génère un avoir portant sur tout ou partie des lignes d'une
     * facture. Jamais confiance dans les IDs de ligne venus de
     * l'appelant : revérifiés intégralement ici (règle impérative T24 :
     * "empêcher qu'une même ligne soit créditée deux fois ou qu'un
     * ensemble d'avoirs successifs dépasse le montant créditable").
     *
     * Barrière à QUATRE niveaux (jamais un seul) :
     * 1. UI — les options proposées ne contiennent déjà que
     *    creditableLinesFor() (confort, jamais la seule protection) ;
     * 2. ICI, avant toute transaction — revérification applicative
     *    immédiate (rejet rapide, cas non concurrent très largement
     *    majoritaire) ;
     * 3. ICI, RE-vérifiée à l'identique À L'INTÉRIEUR de la transaction
     *    (chantier "correctif de concurrence", validé) — jamais
     *    confiance dans la lecture faite avant l'ouverture de la
     *    transaction, qui pourrait être périmée face à un
     *    generateFromInvoice() strictement concurrent sur la même
     *    facture ;
     * 4. Base de données — contrainte UNIQUE sur
     *    credit_note_lines.invoice_line_id (migration), rempart final
     *    même en cas de contournement des trois premiers niveaux. Une
     *    violation à ce niveau (SQLSTATE 23000, vérifié empiriquement
     *    identique sur SQLite/MySQL/PostgreSQL) est interceptée
     *    ci-dessous et traduite dans le MÊME message métier que le
     *    niveau 2/3 — jamais laissée remonter brute (QueryException)
     *    à l'appelant.
     *
     * ============================================================
     * CORRECTIF DE CONCURRENCE (chantier A, validé)
     * ============================================================
     * Défaut identifié : cette méthode n'ouvrait jusqu'ici aucune
     * boucle de nouvelle tentative — contrairement à TOUTE autre
     * méthode recordFor()/generateFromX() de ce projet — et sa seule
     * protection anti-sur-crédit (la contrainte UNIQUE, niveau 4
     * ci-dessus) pouvait donc remonter une Illuminate\Database\QueryException
     * brute à l'appelant (page Filament) en cas de contournement réel
     * des niveaux 2/3 sous concurrence, au lieu du message métier
     * habituel.
     *
     * Mécanisme retenu (adapté du patron recordFor(), MAIS avec une
     * distinction absente ailleurs dans ce projet — voir pourquoi
     * ci-dessous) :
     * 1. Revérification niveau 3 EXÉCUTÉE DANS LA TRANSACTION, avant
     *    toute écriture : ferme la fenêtre de course dans l'immense
     *    majorité des cas (le niveau 4/contrainte UNIQUE n'est alors
     *    jamais atteint).
     * 2. Boucle de 20 tentatives autour de TOUTE la transaction — mais,
     *    contrairement à InvoicePayment/SupplierInvoicePayment/
     *    SupplierCreditNote/CreditNoteLineReturn (dont la seule
     *    protection métier est une comparaison de SOMME, qui lève
     *    toujours un \Exception simple, jamais une QueryException), la
     *    protection ultime ICI est une contrainte UNIQUE en base, qui
     *    lève une VRAIE QueryException (SQLSTATE 23000) en cas de
     *    sur-crédit réellement concurrent (fenêtre du niveau 3 malgré
     *    tout atteinte). Une boucle de retry naïve, calquée à
     *    l'identique sur les autres méthodes, retenterait alors
     *    aveuglément un rejet métier légitime jusqu'à 20 fois avant
     *    d'abandonner avec un message technique trompeur ("contention
     *    trop forte") au lieu du message métier clair habituel.
     * 3. Distinction explicite sur le SQLSTATE de la QueryException
     *    interceptée (vérifié empiriquement, cf. tests) :
     *    - '23000' (violation de contrainte, y compris UNIQUE) -> rejet
     *      métier DÉFINITIF, jamais retenté, même message que le
     *      niveau 2/3 ;
     *    - toute autre valeur (ex. 'HY000' / "database is locked" sous
     *      forte contention SQLite) -> contention transitoire, seule à
     *      être retentée, message et comportement inchangés par
     *      rapport au patron déjà en place ailleurs dans ce projet.
     *
     * DÉCOUVERTE EMPIRIQUE SUPPLÉMENTAIRE (démonstration à processus
     * réels, cf. CreditNoteConcurrencyTest) : cette méthode est la SEULE
     * de ce projet à ouvrir DEUX transactions imbriquées en son sein
     * (CompanySettings::current() -> firstOrCreate()/createOrFirst(),
     * ET CreditNoteSequence::nextNumber() -> SAVEPOINT explicite). Sous
     * contention survenant DANS l'une de ces imbrications, Laravel émet
     * une Illuminate\Database\DeadlockException dédiée — PAS une
     * QueryException (classes SŒURS, toutes deux héritent directement
     * de \PDOException, sans lien de parenté entre elles) — nécessitant
     * son propre bloc catch() distinct, ajouté ci-dessous, sans quoi
     * elle remontait brute exactement comme le défaut initial que ce
     * chantier corrige.
     *
     * @param  array<int, int>  $invoiceLineIds
     */
    public static function generateFromInvoice(
        Invoice $invoice,
        array $invoiceLineIds,
        string $reason,
        string $settlementType,
    ): self {
        if (blank($reason)) {
            throw new \Exception("Le motif de l'avoir est obligatoire.");
        }

        if (! in_array($settlementType, [self::SETTLEMENT_REFUND, self::SETTLEMENT_FUTURE_INVOICE], true)) {
            throw new \Exception(
                "Le mode de règlement de l'avoir doit être précisé (remboursement ou imputation sur facture future)."
            );
        }

        $invoiceLineIds = array_values(array_unique(array_map('intval', $invoiceLineIds)));

        if ($invoiceLineIds === []) {
            throw new \Exception('Sélectionnez au moins une ligne à créditer.');
        }

        // Lignes de la facture elle-même : donnée IMMUABLE (InvoiceLine,
        // T23) — lue une seule fois ici, jamais périmée d'une tentative
        // à l'autre de la boucle de retry ci-dessous, contrairement à
        // $alreadyCreditedLineIds (cf. niveau 3, re-vérifié À CHAQUE
        // tentative, à l'intérieur de la transaction).
        $allLines = $invoice->lines()->get()->keyBy('id');

        foreach ($invoiceLineIds as $lineId) {
            if (! $allLines->has($lineId)) {
                throw new \Exception("Une des lignes sélectionnées n'appartient pas à cette facture.");
            }
        }

        // Niveau 2 (rejet rapide, avant toute transaction) — cas non
        // concurrent très largement majoritaire : évite d'ouvrir une
        // transaction pour un rejet déjà certain à cet instant.
        static::assertLinesCreditable($allLines, $invoiceLineIds);

        $scope = count($invoiceLineIds) === $allLines->count() ? self::SCOPE_TOTAL : self::SCOPE_PARTIAL;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($invoice, $invoiceLineIds, $allLines, $reason, $settlementType, $scope) {
                    // Niveau 3 (chantier A, validé) — revérification
                    // IDENTIQUE au niveau 2, mais relue fraîchement À
                    // L'INTÉRIEUR de la transaction, avant toute
                    // écriture : ferme la fenêtre de course avec un
                    // generateFromInvoice() strictement concurrent sur
                    // la même facture (jamais confiance dans le résultat
                    // du niveau 2, lu avant l'ouverture de la
                    // transaction).
                    static::assertLinesCreditable($allLines, $invoiceLineIds);

                    $creditedLines = $allLines->only($invoiceLineIds);

                    $companySettings = CompanySettings::current();

                    // Le préfixe de numérotation est une convention administrative,
                    // pas une donnée d'identité légale : à la différence des blocs
                    // vendeur/acheteur (D2, copiés depuis Invoice), il est lu tel
                    // que configuré aujourd'hui, sans que cela ne viole D2.
                    $number = CreditNoteSequence::nextNumber(
                        (int) now()->format('Y'),
                        $companySettings->credit_note_number_prefix ?: 'AV',
                    );

                    $creditNote = static::create([
                        'invoice_id' => $invoice->id,
                        'number' => $number,
                        // Point 4 (validé) — toujours la date du jour, jamais
                        // antidatée, quelle que soit la date de la facture.
                        'issued_at' => now()->toDateString(),
                        'scope' => $scope,
                        'reason' => $reason,
                        'settlement_type' => $settlementType,

                        'seller_legal_name' => $invoice->seller_legal_name,
                        'seller_legal_form' => $invoice->seller_legal_form,
                        'seller_share_capital' => $invoice->seller_share_capital,
                        'seller_address' => $invoice->seller_address,
                        'seller_postal_code' => $invoice->seller_postal_code,
                        'seller_city' => $invoice->seller_city,
                        'seller_country' => $invoice->seller_country,
                        'seller_siren' => $invoice->seller_siren,
                        'seller_siret' => $invoice->seller_siret,
                        'seller_rcs_city' => $invoice->seller_rcs_city,
                        'seller_vat_number' => $invoice->seller_vat_number,

                        'customer_id' => $invoice->customer_id,
                        'customer_type' => $invoice->customer_type,
                        'customer_name' => $invoice->customer_name,
                        'customer_company' => $invoice->customer_company,
                        'customer_address' => $invoice->customer_address,
                        'customer_postal_code' => $invoice->customer_postal_code,
                        'customer_city' => $invoice->customer_city,
                        'customer_country' => $invoice->customer_country,
                        'customer_siren' => $invoice->customer_siren,
                        'customer_vat_number' => $invoice->customer_vat_number,

                        'invoice_number_reference' => $invoice->number,
                        'invoice_issued_at_reference' => $invoice->issued_at,
                        'sales_order_reference' => $invoice->sales_order_reference,

                        // Sommes des lignes créditées par CET avoir uniquement —
                        // jamais recalculées différemment, jamais de discount_amount
                        // séparé (la remise est déjà répercutée dans le tax_amount
                        // de chaque InvoiceLine depuis T23).
                        'total_ht' => $creditedLines->sum('subtotal_ht'),
                        'tax_amount' => $creditedLines->sum('tax_amount'),
                        'total_ttc' => $creditedLines->sum('total_ttc'),

                        'user_id' => auth()->id(),
                        'status' => self::STATUS_ISSUED,
                    ]);

                    foreach ($creditedLines as $line) {
                        // Niveau 4, dernier recours — la contrainte UNIQUE
                        // sur invoice_line_id peut encore lever une
                        // QueryException ici (SQLSTATE 23000) si, malgré
                        // le niveau 3 ci-dessus, une autre transaction a
                        // committé une ligne concurrente entre-temps —
                        // interceptée par le catch() ci-dessous, jamais
                        // laissée remonter brute.
                        CreditNoteLine::create([
                            'credit_note_id' => $creditNote->id,
                            'invoice_line_id' => $line->id,
                            'product_id' => $line->product_id,
                            'product_variant_id' => $line->product_variant_id,
                            'product_name' => $line->product_name,
                            'variant_description' => $line->variant_description,
                            'quantity' => $line->quantity,
                            'unit_price_ht' => $line->unit_price_ht,
                            'subtotal_ht' => $line->subtotal_ht,
                            'tax_rate' => $line->tax_rate,
                            'tax_amount' => $line->tax_amount,
                            'total_ttc' => $line->total_ttc,
                        ]);
                    }

                    return $creditNote;
                });
            } catch (\Illuminate\Database\DeadlockException $e) {
                // Découvert empiriquement lors de la mise au point de ce
                // correctif (démonstration à processus réels) : CETTE
                // méthode, contrairement à toute autre recordFor()/
                // generateFromX() de ce projet, ouvre DEUX transactions
                // imbriquées en son sein — CompanySettings::current()
                // (firstOrCreate(), voir Builder::createOrFirst()) ET
                // CreditNoteSequence::nextNumber() (SAVEPOINT explicite).
                // Sous contention (ex. "database is locked" SQLite)
                // survenant DANS l'une de ces transactions imbriquées,
                // Laravel n'émet PAS une QueryException ordinaire mais
                // une Illuminate\Database\DeadlockException dédiée (cf.
                // Connection::handleTransactionException()) — une classe
                // SŒUR de QueryException (toutes deux héritent
                // directement de \PDOException, aucun lien de parenté
                // entre elles), donc jamais interceptée par le catch()
                // QueryException ci-dessous : nécessite son propre bloc.
                //
                // Toujours de la contention transitoire par construction,
                // jamais une violation de contrainte (vérifié dans
                // Illuminate\Database\ConcurrencyErrorDetector : seuls
                // des messages "database is locked"/deadlock déclenchent
                // cette exception) — toujours retentée. Son ->getCode()
                // est PERDU par Laravel (toujours l'entier 0, jamais le
                // SQLSTATE d'origine, cf. code source de
                // handleTransactionException()) : jamais utilisable pour
                // une distinction, contrairement à QueryException
                // ci-dessous.
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new \Exception(
                        "Impossible de générer l'avoir après ".self::MAX_ATTEMPTS." tentatives (contention trop forte).",
                        previous: $e,
                    );
                }

                usleep(random_int(5_000, 20_000));
            } catch (\Illuminate\Database\QueryException $e) {
                // SQLSTATE 23000 = violation de contrainte (y compris
                // UNIQUE), vérifié empiriquement identique sur
                // SQLite/MySQL/PostgreSQL : sur-crédit réellement
                // concurrent, malgré le niveau 3 — rejet métier
                // DÉFINITIF, jamais retenté, même message que le niveau
                // 2/3 (cf. assertLinesCreditable() ci-dessous).
                if ($e->getCode() === '23000') {
                    throw new \Exception(
                        'Une ou plusieurs lignes sélectionnées ont déjà été créditées par un avoir précédent.'
                    );
                }

                // Toute autre QueryException = contention transitoire
                // (ex. "database is locked" sous forte concurrence
                // SQLite, SQLSTATE HY000, vérifié empiriquement) — jamais
                // un rejet métier, qui lève un \Exception simple
                // (intercepté ni ici ni jamais retenté) ou est intercepté
                // ci-dessus.
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new \Exception(
                        "Impossible de générer l'avoir après ".self::MAX_ATTEMPTS." tentatives (contention trop forte).",
                        previous: $e,
                    );
                }

                usleep(random_int(5_000, 20_000));
            }
        }

        // Inatteignable (la boucle retourne ou lève systématiquement
        // ci-dessus) — présent uniquement pour la complétude de type.
        throw new \Exception("Échec inattendu de la génération de l'avoir.");
    }

    /**
     * Niveau 2/3 (chantier "correctif de concurrence", validé) —
     * revérification applicative complète : aucune des lignes
     * sélectionnées n'est déjà créditée par un avoir précédent. Extraite
     * en méthode dédiée car appelée à l'IDENTIQUE à deux moments
     * distincts de generateFromInvoice() (avant l'ouverture de la
     * transaction, pour un rejet rapide dans le cas non concurrent
     * majoritaire ; puis re-exécutée fraîchement à l'intérieur de la
     * transaction, avant toute écriture, pour fermer la fenêtre de
     * course) — jamais une troisième définition qui pourrait diverger.
     *
     * @param  \Illuminate\Support\Collection<int, InvoiceLine>  $allLines
     * @param  array<int, int>  $invoiceLineIds
     */
    private static function assertLinesCreditable($allLines, array $invoiceLineIds): void
    {
        $alreadyCreditedLineIds = CreditNoteLine::query()
            ->whereIn('invoice_line_id', $allLines->keys())
            ->pluck('invoice_line_id')
            ->all();

        if (count($alreadyCreditedLineIds) === $allLines->count()) {
            throw new \Exception('Cette facture est déjà intégralement créditée.');
        }

        if (array_intersect($invoiceLineIds, $alreadyCreditedLineIds) !== []) {
            throw new \Exception(
                'Une ou plusieurs lignes sélectionnées ont déjà été créditées par un avoir précédent.'
            );
        }
    }

    /*
     * =================================================================
     * RELATIONS
     * =================================================================
     */

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
        return $this->hasMany(CreditNoteLine::class);
    }
}
