<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Étape T24 (point 5) — compteur de numérotation légale des avoirs par
 * année civile. STRICTEMENT indépendant d'InvoiceSequence (T23, non
 * modifiée — voir le correctif de sécurité ci-dessous).
 *
 * ============================================================
 * CORRECTIF DE SÉCURITÉ (post-implémentation T24, avant commit)
 * ============================================================
 * Une démonstration de concurrence réelle (10 processus OS
 * indépendants) a reproduit un DOUBLON de numéro avec le mécanisme
 * initial (SELECT verrouillé "lockForUpdate()" + Model::increment()).
 * Cause exacte : Model::increment() calcule la valeur retournée comme
 * "(valeur lue au SELECT précédent) + 1" — jamais une relecture
 * fraîche depuis la base après l'écriture. Si deux processus lisent la
 * même valeur de départ avant que l'un des deux n'écrive, les deux
 * calculent le même résultat local, même si l'incrément SQL lui-même
 * reste atomique côté base (vérifié : le compteur final en base était
 * mathématiquement correct, seule la CHAÎNE retournée à l'appelant
 * avait pu être dupliquée entre deux appels). De plus, lockForUpdate()
 * ne protège que dans une transaction déjà ouverte — nextNumber() n'en
 * ouvrait aucune elle-même — et SQLite ne supporte de toute façon
 * aucun verrouillage ligne par ligne réel (aucun équivalent de
 * "SELECT ... FOR UPDATE").
 *
 * EXACTEMENT LE MÊME DÉFAUT EXISTE dans InvoiceSequence::nextNumber()
 * (T23, déjà committé) — non corrigé ici sur consigne explicite (T23
 * hors périmètre T24) : à traiter par un correctif séparé après T24.
 *
 * Mécanisme retenu ici, à deux niveaux indépendants (jamais un seul) :
 * 1. nextNumber() ouvre DÉSORMAIS SA PROPRE transaction (jamais
 *    seulement celle de l'appelant) — sur MySQL/PostgreSQL,
 *    lockForUpdate() y devient réellement efficace (verrou ligne par
 *    ligne, une seconde transaction concurrente est mise en attente
 *    jusqu'au commit de la première). Sur SQLite, insertOrIgnore()
 *    étant la première instruction et déjà une écriture, elle force
 *    l'acquisition du verrou d'écriture SQLite (verrouillage au niveau
 *    du fichier, pas de la ligne) dès le début de la transaction —
 *    toute transaction concurrente tentant sa propre écriture avant le
 *    commit de la première échoue ou attend, jamais un entrelacement
 *    silencieux.
 * 2. Vérification optimiste, indépendante du moteur : l'écriture
 *    finale porte une clause WHERE sur la valeur exacte lue
 *    (last_number = $valeurLue). Si une autre transaction a modifié la
 *    ligne entre-temps (quelle qu'en soit la raison, y compris une
 *    éventuelle lacune du niveau 1 sur un moteur non prévu), 0 ligne
 *    est affectée : une exception est levée plutôt que de risquer de
 *    retourner un numéro potentiellement dupliqué — JAMAIS la valeur
 *    locale pré-incrémentée n'est utilisée pour construire le numéro
 *    retourné, uniquement le résultat de cette écriture vérifiée.
 * Une nouvelle tentative automatique (avec délai aléatoire) absorbe
 * ces conflits, rares et attendus sous forte contention, sans jamais
 * les répercuter sur l'appelant en usage normal.
 *
 * Rollback : nextNumber() étant appelée depuis la transaction englobante
 * de CreditNote::generateFromInvoice(), un DB::transaction() imbriqué
 * ici utilise un SAVEPOINT (Laravel) : si la transaction englobante
 * échoue et annule tout, la réservation du numéro est annulée avec
 * elle — le numéro redevient disponible pour le prochain appel réussi
 * (jamais de doublon, une lacune n'est possible que si l'échec survient
 * APRÈS un commit réel, ce qui n'arrive jamais ici).
 */
class CreditNoteSequence extends Model
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
                            "Conflit de concurrence détecté lors de la réservation du numéro d'avoir (année {$year})."
                        );
                    }

                    return sprintf('%s-%d-%06d', $prefix, $year, $candidateNumber);
                });
            } catch (\Throwable $e) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new \Exception(
                        "Impossible de générer un numéro d'avoir après ".self::MAX_ATTEMPTS." tentatives (contention trop forte).",
                        previous: $e,
                    );
                }

                usleep(random_int(5_000, 20_000));
            }
        }

        // Inatteignable (la boucle retourne ou lève systématiquement
        // ci-dessus) — présent uniquement pour la complétude de type.
        throw new \Exception("Échec inattendu de la génération du numéro d'avoir.");
    }
}
