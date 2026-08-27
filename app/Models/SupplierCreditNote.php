<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Chantier "avoir fournisseur" — un avoir reçu d'un fournisseur contre
 * une SupplierInvoice précise (T28), immuable dès son enregistrement
 * (cf. booted() ci-dessous), même principe que SupplierInvoice/
 * SupplierInvoicePayment (T28/T30) : aucune correction/annulation en
 * V1 (décision validée).
 *
 * Architecture validée (étude préalable, comparaison A/B) :
 * document TIERS reçu, jamais émis par Magarrou — donc :
 * - traité au MONTANT GLOBAL de la facture fournisseur créditée
 *   (décision 2), jamais ligne à ligne : SupplierInvoice n'a aucune
 *   ligne produit (contrairement à Invoice/InvoiceLine) — un avoir
 *   "par ligne" nécessiterait de créer d'abord une SupplierInvoiceLine,
 *   hors périmètre de ce chantier ;
 * - rattaché OBLIGATOIREMENT à une SupplierInvoice précise (décision
 *   1), jamais directement à un PurchaseOrder (qui peut porter
 *   plusieurs factures fournisseur — un rattachement direct au PO
 *   introduirait une ambiguïté d'allocation entre elles) ;
 * - numéro DU FOURNISSEUR, texte libre (décision 6) — jamais généré
 *   par Magarrou, même logique que SupplierInvoice.supplier_invoice_number
 *   (Magarrou n'émet rien ici, elle enregistre un document tiers) ;
 * - `reason` est OPTIONNEL (décision validée), contrairement à
 *   CreditNote.reason (obligatoire) : simple aide à la traçabilité.
 *
 * Le retour physique de marchandise (décrément de stock) est
 * explicitement HORS PÉRIMÈTRE de ce chantier (décision 3) : ce modèle
 * reste STRICTEMENT la partie comptable (décision 5), aucune relation
 * vers StockMovement, aucun impact sur le stock. Un futur chantier
 * séparé rattachera le retour physique à PurchaseOrderItem (donnée
 * produit/quantité fiable), jamais déduit de ce modèle (décision 4).
 *
 * Point d'entrée UNIQUE de création : recordFor() — même convention
 * que SupplierInvoicePayment::recordFor()/CreditNote::generateFromInvoice()
 * (méthode statique sur le modèle, jamais un create() direct depuis
 * l'interface).
 *
 * ============================================================
 * CONCURRENCE / ATOMICITÉ — mécanisme identique à
 * SupplierInvoicePayment::recordFor() (T30)
 * ============================================================
 * 1. DB::transaction() + lockForUpdate() sur la SupplierInvoice
 *    elle-même : sérialise réellement deux enregistrements concurrents
 *    d'avoir pour LA MÊME facture.
 * 2. L'INSERT de l'avoir est exécuté AVANT la vérification (jamais
 *    l'inverse) : sur SQLite (aucun verrouillage ligne par ligne réel),
 *    c'est cet INSERT qui force le verrou d'écriture au niveau du
 *    fichier dès le début de la transaction.
 * 3. Vérification APRÈS écriture, dans la même transaction : si le
 *    cumul des avoirs dépasse le total TTC de la facture, une
 *    exception est levée (ROLLBACK SQL, jamais un appel à delete() qui
 *    échouerait de toute façon sur un modèle immuable) — l'avoir n'est
 *    jamais persisté.
 * 4. Boucle de nouvelle tentative (20 essais, délai aléatoire) qui ne
 *    retente QUE les Illuminate\Database\QueryException (contention
 *    transitoire type "database is locked") — jamais l'exception
 *    métier "dépasse le total", qui se propage immédiatement, message
 *    inchangé.
 *
 * PRÉCISION DES MONTANTS : (float) + round(..., 2), cohérent avec
 * SupplierInvoicePayment/Invoice/CreditNote — jamais bcmath ni
 * centimes entiers. La somme est calculée CÔTÉ BASE (->sum('total_ttc')
 * sur la colonne decimal(10,2)), jamais en additionnant manuellement
 * des valeurs PHP récupérées une par une.
 */
class SupplierCreditNote extends Model
{
    protected $guarded = [];

    private const MAX_ATTEMPTS = 20;

    protected $casts = [
        'credit_note_date' => 'date',
        'total_ht' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierCreditNote $creditNote) {
            $creditNote->user_id ??= auth()->id();
        });

        /*
         * =================================================================
         * IMMUABILITÉ (décision validée : aucune exception)
         * =================================================================
         */
        static::updating(function (): void {
            throw new \Exception(
                'Un avoir fournisseur ne peut pas être modifié après son enregistrement.'
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                'Un avoir fournisseur ne peut pas être supprimé après son enregistrement.'
            );
        });
    }

    /**
     * Enregistre un avoir reçu d'un fournisseur contre une facture
     * fournisseur. Jamais confiance dans les valeurs venues de
     * l'appelant : revérifiées intégralement ici (numéro renseigné,
     * montant > 0, cumul jamais supérieur au total TTC de la facture),
     * sous transaction et verrouillage (cf. documentation de tête de
     * classe).
     */
    public static function recordFor(
        SupplierInvoice $invoice,
        string $number,
        string $creditNoteDate,
        float $totalHt,
        float $taxAmount,
        float $totalTtc,
        ?string $reason = null,
        ?string $notes = null,
    ): self {
        if (blank($number)) {
            throw new \Exception("Le numéro de l'avoir fournisseur est obligatoire.");
        }

        if ($totalTtc <= 0) {
            throw new \Exception("Le montant de l'avoir doit être supérieur à zéro.");
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($invoice, $number, $creditNoteDate, $totalHt, $taxAmount, $totalTtc, $reason, $notes) {
                    $lockedInvoice = SupplierInvoice::query()
                        ->whereKey($invoice->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $creditNote = static::create([
                        'supplier_invoice_id' => $lockedInvoice->id,
                        'supplier_credit_note_number' => $number,
                        'credit_note_date' => $creditNoteDate,
                        'total_ht' => $totalHt,
                        'tax_amount' => $taxAmount,
                        'total_ttc' => $totalTtc,
                        'reason' => $reason,
                        'notes' => $notes,
                    ]);

                    $totalCredited = round((float) static::where('supplier_invoice_id', $lockedInvoice->id)->sum('total_ttc'), 2);

                    if ($totalCredited > round((float) $lockedInvoice->total_ttc, 2)) {
                        // Rejet métier définitif : jamais retenté, message
                        // inchangé (distinct d'une QueryException technique
                        // ci-dessous).
                        throw new \Exception(
                            'Le montant total des avoirs dépasse le total TTC de cette facture fournisseur.'
                        );
                    }

                    return $creditNote;
                });
            } catch (\Illuminate\Database\QueryException $e) {
                // Contention transitoire uniquement (ex. "database is
                // locked" sous forte concurrence SQLite) — jamais un
                // dépassement de total, qui lève un \Exception simple,
                // non capturé ici, et se propage immédiatement.
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new \Exception(
                        "Impossible d'enregistrer l'avoir après ".self::MAX_ATTEMPTS." tentatives (contention trop forte).",
                        previous: $e,
                    );
                }

                usleep(random_int(5_000, 20_000));
            }
        }

        // Inatteignable (la boucle retourne ou lève systématiquement
        // ci-dessus) — présent uniquement pour la complétude de type.
        throw new \Exception("Échec inattendu de l'enregistrement de l'avoir fournisseur.");
    }

    /**
     * Montant total déjà crédité (tous avoirs confondus) pour une
     * facture fournisseur donnée — calculé à la demande, jamais un
     * champ stocké. Utilisé à la fois pour proposer/masquer l'action
     * Filament (confort d'UI, jamais la seule garde) et implicitement
     * par recordFor() ci-dessus (revérification applicative complète,
     * toujours refaite indépendamment).
     */
    public static function totalCreditedFor(SupplierInvoice $invoice): float
    {
        return round((float) static::where('supplier_invoice_id', $invoice->id)->sum('total_ttc'), 2);
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
