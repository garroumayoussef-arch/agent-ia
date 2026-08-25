<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Étape T23 (D6) — compteur de numérotation légale par année civile.
 * Aucune Resource Filament dédiée : purement interne, utilisé
 * exclusivement par Invoice::generateFromSalesOrder().
 *
 * ============================================================
 * CORRECTIF DE CONCURRENCE (T25-A)
 * ============================================================
 * Défaut identifié et documenté dès T24 (jamais corrigé jusqu'ici sur
 * consigne explicite : T23 était alors déjà committée, hors périmètre
 * T24) : une démonstration à 10 processus OS concurrents avait
 * reproduit un DOUBLON avec ce même mécanisme sur CreditNoteSequence,
 * avant sa correction. Cause exacte : Model::increment('last_number')
 * calcule la valeur PHP retournée comme "(valeur lue au SELECT
 * précédent) + 1" — jamais une relecture fraîche depuis la base après
 * l'écriture. Si deux processus lisent la même valeur de départ avant
 * que l'un des deux n'écrive, les deux calculent et retournent la même
 * chaîne, même si l'incrément SQL lui-même reste atomique côté base.
 * De plus, lockForUpdate() n'a d'effet que dans une transaction déjà
 * ouverte — nextNumber() n'en ouvrait aucune elle-même — et SQLite ne
 * supporte de toute façon aucun verrouillage ligne par ligne réel
 * (aucun équivalent de "SELECT ... FOR UPDATE").
 *
 * Mécanisme retenu ici : report exact, à l'identique, du correctif déjà
 * validé et démontré sur CreditNoteSequence::nextNumber() (T24) — voir
 * sa documentation pour le détail du raisonnement complet. Résumé :
 * 1. nextNumber() ouvre désormais SA PROPRE transaction (jamais
 *    seulement celle de l'appelant), rendant lockForUpdate() réellement
 *    efficace sur MySQL/PostgreSQL, et forçant sur SQLite l'acquisition
 *    du verrou d'écriture fichier dès insertOrIgnore().
 * 2. Vérification optimiste, indépendante du moteur : l'écriture finale
 *    porte une clause WHERE sur la valeur exacte lue
 *    (last_number = $valeurLue). Si 0 ligne est affectée, une exception
 *    est levée plutôt que de risquer un numéro dupliqué — jamais la
 *    valeur locale pré-incrémentée n'est utilisée pour construire le
 *    numéro retourné, uniquement le résultat de cette écriture vérifiée.
 * Une nouvelle tentative automatique (délai aléatoire) absorbe ces
 * conflits, rares et attendus sous forte contention.
 *
 * Rollback : nextNumber() étant appelée depuis la transaction englobante
 * de Invoice::generateFromSalesOrder(), le DB::transaction() imbriqué
 * ici utilise un SAVEPOINT (Laravel) : si la transaction englobante
 * échoue et annule tout, la réservation du numéro est annulée avec
 * elle — le numéro redevient disponible pour le prochain appel réussi.
 *
 * Format des numéros retournés, signature de nextNumber() : strictement
 * inchangés — aucun impact sur le comportement déjà validé de T23.
 */
class InvoiceSequence extends Model
{
    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'last_number' => 'integer',
    ];

    private const MAX_ATTEMPTS = 20;

    public static function nextNumber(int $year, string $prefix): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($year, $prefix) {
                    static::query()->insertOrIgnore([
                        'year' => $year,
                        'last_number' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $sequence = static::query()
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $candidateNumber = $sequence->last_number + 1;

                    // Écriture ET vérification en une seule requête
                    // atomique : jamais confiance dans la valeur locale
                    // ($sequence->last_number) pour affirmer que
                    // $candidateNumber est réellement celui obtenu.
                    $affected = static::query()
                        ->where('year', $year)
                        ->where('last_number', $sequence->last_number)
                        ->update([
                            'last_number' => $candidateNumber,
                            'updated_at' => now(),
                        ]);

                    if ($affected !== 1) {
                        throw new \Exception(
                            "Conflit de concurrence détecté lors de la réservation du numéro de facture (année {$year})."
                        );
                    }

                    return sprintf('%s-%d-%06d', $prefix, $year, $candidateNumber);
                });
            } catch (\Throwable $e) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new \Exception(
                        "Impossible de générer un numéro de facture après ".self::MAX_ATTEMPTS." tentatives (contention trop forte).",
                        previous: $e,
                    );
                }

                usleep(random_int(5_000, 20_000));
            }
        }

        // Inatteignable (la boucle retourne ou lève systématiquement
        // ci-dessus) — présent uniquement pour la complétude de type.
        throw new \Exception("Échec inattendu de la génération du numéro de facture.");
    }
}
