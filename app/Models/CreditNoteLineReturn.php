<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * Chantier "retour physique" (Option 3b, validée) — un événement de
 * retour physique déclaré contre une CreditNoteLine, indépendant du
 * montant financier de l'avoir (T24, chantier D1-D7) : un avoir peut
 * exister sans qu'aucun retour physique ne soit jamais déclaré (geste
 * commercial, remboursement sans retour), et un retour peut être
 * déclaré en plusieurs fois (retour partiel, retour différé dans le
 * temps).
 *
 * Immuable dès l'enregistrement (cf. booted() ci-dessous), à
 * l'identique de InvoicePayment/SupplierInvoicePayment — aucune
 * correction/annulation en V1 (décision validée).
 *
 * Point d'entrée UNIQUE de création : recordFor() — même convention
 * que Invoice::generateFromSalesOrder()/CreditNote::generateFromInvoice()/
 * InvoicePayment::recordFor() (méthode statique sur le modèle, jamais
 * un create() direct depuis l'interface).
 *
 * ============================================================
 * RÈGLE CENTRALE (validée) : avoir financier ≠ retour physique ≠
 * mouvement de stock
 * ============================================================
 * CreditNote::generateFromInvoice() ne crée JAMAIS de
 * CreditNoteLineReturn — un avoir sans retour physique déclaré ne
 * modifie jamais le stock. Seul un appel explicite à recordFor()
 * peut, selon la condition choisie, générer un StockMovement :
 * - 'vendable'   -> StockMovement('return') créé, stock incrémenté ;
 * - 'defectueux' -> AUCUN StockMovement, sous aucun prétexte.
 *
 * ============================================================
 * PRODUIT/VARIANTE OBLIGATOIRE (règle définitive validée)
 * ============================================================
 * Un retour — vendable OU défectueux, sans distinction — est refusé si
 * la CreditNoteLine ciblée n'a ni product_id ni product_variant_id
 * exploitable. L'avoir financier reste parfaitement valide dans ce
 * cas ; seul l'enregistrement d'un retour physique est bloqué, avant
 * toute écriture (aucun CreditNoteLineReturn, aucun StockMovement).
 *
 * ============================================================
 * CONCURRENCE / ATOMICITÉ — même mécanisme validé en T30/T31/D5
 * ============================================================
 * 1. DB::transaction() + lockForUpdate() sur la CreditNoteLine
 *    elle-même : sérialise réellement deux enregistrements concurrents
 *    de retour pour LA MÊME ligne.
 * 2. L'INSERT du retour est exécuté AVANT la vérification (jamais
 *    l'inverse) : sur SQLite (aucun verrouillage ligne par ligne réel),
 *    c'est cet INSERT qui force le verrou d'écriture au niveau du
 *    fichier dès le début de la transaction.
 * 3. Vérification APRÈS écriture, dans la même transaction : si le
 *    cumul des retours dépasse la quantité créditée de la ligne, une
 *    exception est levée (ROLLBACK SQL, jamais un appel à delete() qui
 *    échouerait de toute façon sur un modèle immuable) — le retour
 *    n'est jamais persisté, et le StockMovement (s'il devait être
 *    créé) n'est JAMAIS atteint dans le code, placé après cette
 *    vérification.
 * 4. Boucle de nouvelle tentative (20 essais, délai aléatoire) qui ne
 *    retente QUE les Illuminate\Database\QueryException (contention
 *    transitoire type "database is locked") — jamais l'exception
 *    métier "dépasse la quantité", qui se propage immédiatement,
 *    message inchangé.
 *
 * ============================================================
 * PRÉCISION — cohérent avec l'architecture existante
 * ============================================================
 * Quantités toujours des entiers (jamais de décimal) : un retour
 * physique porte sur des unités entières, jamais une fraction.
 */
class CreditNoteLineReturn extends Model
{
    protected $guarded = [];

    private const MAX_ATTEMPTS = 20;

    public const CONDITION_SELLABLE = 'vendable';

    public const CONDITION_DEFECTIVE = 'defectueux';

    protected $casts = [
        'returned_at' => 'date',
        'quantity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (CreditNoteLineReturn $return) {
            $return->user_id ??= auth()->id();
        });

        /*
         * =================================================================
         * IMMUABILITÉ (décision validée : aucune exception)
         * =================================================================
         */
        static::updating(function (): void {
            throw new \Exception(
                'Un retour ne peut pas être modifié après son enregistrement.'
            );
        });

        static::deleting(function (): void {
            throw new \Exception(
                'Un retour ne peut pas être supprimé après son enregistrement.'
            );
        });
    }

    /**
     * Enregistre un retour physique contre une ligne d'avoir. Jamais
     * confiance dans les valeurs venues de l'appelant : revérifiées
     * intégralement ici (quantité > 0, condition valide, produit/
     * variante identifiable, cumul jamais supérieur à la quantité
     * créditée de la ligne), sous transaction et verrouillage (cf.
     * documentation de tête de classe).
     */
    public static function recordFor(
        CreditNoteLine $line,
        int $quantity,
        string $condition,
        string $returnedAt,
        ?string $reference = null,
        ?string $notes = null,
    ): self {
        if ($quantity <= 0) {
            throw new \Exception('La quantité retournée doit être supérieure à zéro.');
        }

        if (! in_array($condition, [self::CONDITION_SELLABLE, self::CONDITION_DEFECTIVE], true)) {
            throw new \Exception(
                "L'état du produit retourné doit être précisé (vendable ou défectueux)."
            );
        }

        // Produit/variante obligatoire (règle définitive validée) —
        // vérifié AVANT toute transaction, identiquement pour vendable
        // ET défectueux : aucun retour physique ne peut être rattaché
        // à une ligne sans cible produit identifiable, même si l'avoir
        // financier lui-même reste parfaitement valide.
        if (blank($line->product_id) && blank($line->product_variant_id)) {
            throw new \Exception(
                'Le retour physique nécessite un produit ou une variante identifiable sur cette ligne.'
            );
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $return = DB::transaction(function () use ($line, $quantity, $condition, $returnedAt, $reference, $notes) {
                    $lockedLine = CreditNoteLine::query()
                        ->whereKey($line->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $return = static::create([
                        'credit_note_line_id' => $lockedLine->id,
                        'product_id' => $lockedLine->product_id,
                        'product_variant_id' => $lockedLine->product_variant_id,
                        'quantity' => $quantity,
                        'condition' => $condition,
                        'returned_at' => $returnedAt,
                        'reference' => $reference,
                        'notes' => $notes,
                    ]);

                    $totalReturned = static::where('credit_note_line_id', $lockedLine->id)->sum('quantity');

                    if ($totalReturned > $lockedLine->quantity) {
                        // Rejet métier définitif : jamais retenté, message
                        // inchangé (distinct d'une QueryException technique
                        // ci-dessous).
                        throw new \Exception(
                            'La quantité retournée dépasse la quantité restant retournable sur cette ligne.'
                        );
                    }

                    // Règle centrale (validée) : SEUL un retour
                    // 'vendable' génère un mouvement de stock. Un retour
                    // 'defectueux' ne touche jamais StockMovement, sous
                    // aucun prétexte — placé après la vérification du
                    // cumul ci-dessus : jamais atteint si le cumul est
                    // dépassé (rollback avant cette ligne).
                    if ($condition === self::CONDITION_SELLABLE) {
                        StockMovement::create([
                            'product_id' => $lockedLine->product_id,
                            'product_variant_id' => $lockedLine->product_variant_id,
                            'type' => 'return',
                            'quantity' => $quantity,
                            'credit_note_line_return_id' => $return->id,
                            'reference' => $lockedLine->creditNote->number,
                            'notes' => "Retour physique vendable — avoir {$lockedLine->creditNote->number}",
                        ]);
                    }

                    return $return;
                });

                // Chantier "Notifications & communication" V1
                // (D1/D5/D8/D12, validés) — atteint UNIQUEMENT sur le
                // chemin de succès de CETTE tentative. Requêtes FRAÎCHES
                // via l'appel de méthode de relation (jamais l'accesseur
                // de propriété $return->creditNoteLine) : ne met jamais
                // en cache une relation sur $return, pour ne jamais
                // polluer un éventuel ->toArray() ultérieur de l'appelant
                // (même précaution que Invoice::generateFromInvoice()
                // ci-dessus, découverte empiriquement pendant ce
                // chantier).
                $creditNoteLine = $return->creditNoteLine()->first();
                $creditNote = $creditNoteLine?->creditNote()->first();
                $customerEmail = $creditNote?->customer_id
                    ? $creditNote->customer()->first()?->email
                    : null;

                $log = NotificationLog::reserve($return, 'customer_return_issued', 'email', $customerEmail);

                if ($log !== null && $log->status === NotificationLog::STATUS_QUEUED) {
                    \Illuminate\Support\Facades\Mail::to($customerEmail)
                        ->queue(new \App\Mail\CustomerReturnIssuedMail($return, $log->id));
                }

                return $return;
            } catch (\Illuminate\Database\QueryException $e) {
                // Contention transitoire uniquement (ex. "database is
                // locked" sous forte concurrence SQLite) — jamais un
                // dépassement de quantité, qui lève un \Exception simple,
                // non capturé ici, et se propage immédiatement.
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new \Exception(
                        "Impossible d'enregistrer le retour après ".self::MAX_ATTEMPTS." tentatives (contention trop forte).",
                        previous: $e,
                    );
                }

                usleep(random_int(5_000, 20_000));
            }
        }

        // Inatteignable (la boucle retourne ou lève systématiquement
        // ci-dessus) — présent uniquement pour la complétude de type.
        throw new \Exception("Échec inattendu de l'enregistrement du retour.");
    }

    /**
     * Quantité déjà retournée (toutes conditions confondues) pour une
     * ligne d'avoir donnée — calculée à la demande, jamais un champ
     * stocké. Utilisée à la fois pour proposer les lignes encore
     * retournables côté Filament (confort d'UI, jamais la seule garde)
     * et implicitement par recordFor() ci-dessus (revérification
     * applicative complète, toujours refaite indépendamment).
     */
    public static function totalReturnedFor(CreditNoteLine $line): int
    {
        return (int) static::where('credit_note_line_id', $line->id)->sum('quantity');
    }

    public function creditNoteLine(): BelongsTo
    {
        return $this->belongsTo(CreditNoteLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Le StockMovement généré par ce retour — null si condition =
     * 'defectueux' (aucun mouvement créé dans ce cas, cf. recordFor()).
     */
    public function stockMovement(): HasOne
    {
        return $this->hasOne(StockMovement::class);
    }
}
