<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Étape T31 — un paiement enregistré contre une Invoice. Symétrique
 * exact de SupplierInvoicePayment (T30) : immuable dès l'enregistrement
 * (cf. booted()), aucune correction/annulation en V1 (décision
 * validée).
 *
 * Point d'entrée UNIQUE de création : recordFor() — même convention
 * que Invoice::generateFromSalesOrder()/CreditNote::generateFromInvoice()/
 * SupplierInvoicePayment::recordFor() (méthode statique sur le modèle,
 * jamais un create() direct depuis l'interface).
 *
 * ============================================================
 * CONCURRENCE / ATOMICITÉ — même mécanisme validé en T30
 * ============================================================
 * 1. DB::transaction() + lockForUpdate() sur l'Invoice elle-même : sur
 *    MySQL/PostgreSQL, sérialise réellement deux enregistrements
 *    concurrents pour LA MÊME facture (verrou ligne par ligne).
 * 2. L'INSERT du paiement est exécuté AVANT la vérification (jamais
 *    l'inverse) : sur SQLite (aucun verrouillage ligne par ligne réel),
 *    c'est cet INSERT qui force le verrou d'écriture au niveau du
 *    fichier dès le début de la transaction.
 * 3. Vérification APRÈS écriture, dans la même transaction : si le
 *    total dépasse le TTC de la facture, une exception est levée
 *    (ROLLBACK SQL, jamais un appel à delete() qui échouerait de toute
 *    façon sur un modèle immuable) — le paiement n'est jamais persisté.
 * 4. Boucle de nouvelle tentative (20 essais, délai aléatoire) qui ne
 *    retente QUE les Illuminate\Database\QueryException (contention
 *    transitoire type "database is locked", découverte et corrigée
 *    pendant T30) — jamais l'exception métier "dépasse le solde", qui
 *    se propage immédiatement, message inchangé.
 *
 * ============================================================
 * PRÉCISION DES MONTANTS — cohérent avec l'architecture existante
 * ============================================================
 * (float) + round(..., 2) à chaque étape, jamais bcmath ni centimes
 * entiers. Somme calculée CÔTÉ BASE (->sum('amount') sur la colonne
 * decimal(10,2)), jamais en additionnant manuellement des valeurs PHP
 * récupérées une par une — toujours arrondie à 2 décimales avant toute
 * comparaison.
 */
class InvoicePayment extends Model
{
    protected $guarded = [];

    private const MAX_ATTEMPTS = 20;

    protected $casts = [
        'paid_at' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (InvoicePayment $payment) {
            $payment->user_id ??= auth()->id();
        });

        /*
         * =================================================================
         * IMMUABILITÉ (décision validée : aucune exception)
         * =================================================================
         */
        static::updating(function (): void {
            throw new \Exception(
                'Un paiement ne peut pas être modifié après son enregistrement.'
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                'Un paiement ne peut pas être supprimé après son enregistrement.'
            );
        });
    }

    /**
     * Enregistre un paiement contre une facture. Jamais confiance dans
     * le montant venu de l'appelant : revérifié intégralement ici
     * (montant > 0, cumul jamais supérieur au total TTC), sous
     * transaction et verrouillage (cf. documentation de tête de
     * classe).
     */
    public static function recordFor(
        Invoice $invoice,
        float $amount,
        string $paidAt,
        ?string $reference = null,
        ?string $notes = null,
    ): self {
        if ($amount <= 0) {
            throw new \Exception('Le montant du paiement doit être supérieur à zéro.');
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($invoice, $amount, $paidAt, $reference, $notes) {
                    $lockedInvoice = Invoice::query()
                        ->whereKey($invoice->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $payment = static::create([
                        'invoice_id' => $lockedInvoice->id,
                        'amount' => $amount,
                        'paid_at' => $paidAt,
                        'reference' => $reference,
                        'notes' => $notes,
                    ]);

                    $totalPaid = round((float) static::where('invoice_id', $lockedInvoice->id)->sum('amount'), 2);

                    if ($totalPaid > round((float) $lockedInvoice->total_ttc, 2)) {
                        // Rejet métier définitif : jamais retenté, message
                        // inchangé (distinct d'une QueryException technique
                        // ci-dessous).
                        throw new \Exception(
                            'Le montant du paiement dépasse le solde restant dû sur cette facture.'
                        );
                    }

                    return $payment;
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // Contention transitoire uniquement (ex. "database is
                // locked" sous forte concurrence SQLite) — jamais un
                // dépassement de solde, qui lève un \Exception simple,
                // non capturé ici, et se propage immédiatement.
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new \Exception(
                        "Impossible d'enregistrer le paiement après ".self::MAX_ATTEMPTS." tentatives (contention trop forte).",
                        previous: $e,
                    );
                }

                usleep(random_int(5_000, 20_000));
            }
        }

        // Inatteignable (la boucle retourne ou lève systématiquement
        // ci-dessus) — présent uniquement pour la complétude de type.
        throw new \Exception("Échec inattendu de l'enregistrement du paiement.");
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
