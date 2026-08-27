<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Étape T30 — un paiement enregistré contre une SupplierInvoice.
 * Immuable dès l'enregistrement (cf. booted()), comme SupplierInvoice
 * (T28) : aucune correction/annulation en V1 (décision validée).
 *
 * Point d'entrée UNIQUE de création : recordFor() — même convention
 * que Invoice::generateFromSalesOrder()/CreditNote::generateFromInvoice()/
 * StockTransfer::execute() (méthode statique sur le modèle, jamais un
 * create() direct depuis l'interface).
 *
 * ============================================================
 * CONCURRENCE / ATOMICITÉ (exigence explicite avant implémentation)
 * ============================================================
 * Sans précaution, deux enregistrements concurrents de paiement
 * pourraient tous deux lire "le solde restant" avant que l'un des deux
 * n'écrive, et tous deux accepter un montant qui, cumulé, dépasse le
 * total TTC de la facture — même défaut de principe que celui
 * diagnostiqué et corrigé en T25 sur InvoiceSequence/CreditNoteSequence.
 *
 * Mécanisme retenu, adapté d'un agrégat multi-lignes (pas un simple
 * compteur) :
 * 1. DB::transaction() + lockForUpdate() sur la SupplierInvoice
 *    elle-même : sur MySQL/PostgreSQL, sérialise réellement deux
 *    enregistrements concurrents pour LA MÊME facture (verrou ligne
 *    par ligne).
 * 2. L'INSERT du paiement est exécuté AVANT la vérification (jamais
 *    l'inverse) : sur SQLite (aucun verrouillage ligne par ligne réel),
 *    c'est cet INSERT qui force le verrou d'écriture au niveau du
 *    fichier dès le début de la transaction — un second recordFor()
 *    concurrent pour la même facture est mis en attente jusqu'au
 *    commit/rollback de celui-ci, jamais un entrelacement silencieux.
 * 3. Vérification APRÈS écriture, dans la même transaction : la seule
 *    lecture qui compte est celle qui inclut CE paiement. Si le total
 *    dépasse le TTC de la facture, une exception est levée : la
 *    transaction est annulée (ROLLBACK SQL, jamais un appel à delete()
 *    qui échouerait de toute façon sur un modèle immuable) — le
 *    paiement n'est jamais persisté.
 * Un dépassement réel du solde est un rejet métier définitif (jamais
 * retenté, message préservé tel quel).
 *
 * CORRECTIF (découvert par la démonstration à 10 processus demandée) :
 * une première version sans boucle de nouvelle tentative a échoué —
 * 4 paiements acceptés au lieu de 5 attendus sous 10 processus
 * concurrents. Cause : sous forte contention SQLite (10 transactions
 * simultanées sur le même fichier), une transaction par ailleurs
 * légitime peut essuyer une erreur transitoire "database is locked"
 * (Illuminate\Database\QueryException) — un rejet purement technique,
 * jamais un dépassement de solde. La confondre avec un rejet métier
 * aurait refusé à tort un paiement pourtant valide. Correctif : une
 * boucle de nouvelle tentative (20 essais, délai aléatoire, même
 * paramètres qu'InvoiceSequence/CreditNoteSequence) qui ne retente QUE
 * les QueryException — jamais l'exception métier "dépasse le solde",
 * qui se propage immédiatement, sans délai, message inchangé.
 *
 * ============================================================
 * CORRECTIF (chantier C, validé) — plafond NET des avoirs
 * ============================================================
 * Le plafond était jusqu'ici total_ttc BRUT — incohérent avec
 * InvoicePayment::recordFor() (D5, chantier "réconciliation avoirs"
 * côté vente), qui plafonne au solde NET (total_ttc − avoirs) depuis
 * l'introduction de CreditNote. Cette incohérence avait subsisté ici
 * car SupplierCreditNote (T32) n'existait pas encore au moment de T30.
 * Corrigé : le plafond est désormais total_ttc − SupplierInvoice::creditedAmount()
 * (solde NET après avoirs fournisseur), symétrique exact de D5. Comme
 * pour D5, creditedAmount() est lu APRÈS le verrouillage de la
 * SupplierInvoice (lockForUpdate() ci-dessous), donc dans la même
 * transaction que la vérification du montant payé — même niveau de
 * fraîcheur que $totalPaid. Changement de comportement assumé (même
 * décision que D5) : un paiement aujourd'hui accepté (≤ total_ttc brut)
 * peut désormais être refusé si un avoir fournisseur existe déjà sur
 * la facture.
 *
 * ============================================================
 * PRÉCISION DES MONTANTS
 * ============================================================
 * Cohérent avec l'architecture existante (PurchaseOrder::applyTaxAllocation(),
 * Invoice::generateFromSalesOrder(), CommercialOverview, PurchasingOverview) :
 * (float) + round(..., 2) à chaque étape, jamais bcmath ni centimes
 * entiers (introduire une représentation différente serait incohérent
 * avec tout le reste du projet). La somme est calculée CÔTÉ BASE
 * (->sum('amount') sur la colonne decimal(10,2)), jamais en additionnant
 * manuellement des valeurs PHP récupérées une par une — et toujours
 * arrondie à 2 décimales avant toute comparaison.
 */
class SupplierInvoicePayment extends Model
{
    protected $guarded = [];

    private const MAX_ATTEMPTS = 20;

    protected $casts = [
        'paid_at' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierInvoicePayment $payment) {
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
     * Enregistre un paiement contre une facture fournisseur. Jamais
     * confiance dans le montant venu de l'appelant : revérifié
     * intégralement ici (montant > 0, cumul jamais supérieur au total
     * TTC), sous transaction et verrouillage (cf. documentation de tête
     * de classe).
     */
    public static function recordFor(
        SupplierInvoice $invoice,
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
                    $lockedInvoice = SupplierInvoice::query()
                        ->whereKey($invoice->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $payment = static::create([
                        'supplier_invoice_id' => $lockedInvoice->id,
                        'amount' => $amount,
                        'paid_at' => $paidAt,
                        'reference' => $reference,
                        'notes' => $notes,
                    ]);

                    $totalPaid = round((float) static::where('supplier_invoice_id', $lockedInvoice->id)->sum('amount'), 2);

                    // Chantier C (validé) — plafond NET (total_ttc −
                    // avoirs), jamais total_ttc brut : cf. documentation
                    // de tête de classe. $lockedInvoice->creditedAmount()
                    // est lu ici, une fois la SupplierInvoice verrouillée,
                    // pour rester au même niveau de fraîcheur que
                    // $totalPaid ci-dessus (symétrique exact de D5 sur
                    // InvoicePayment::recordFor()).
                    $netCeiling = round((float) $lockedInvoice->total_ttc - $lockedInvoice->creditedAmount(), 2);

                    if ($totalPaid > $netCeiling) {
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

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
