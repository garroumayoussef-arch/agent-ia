<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Chantier "Notifications & communication" V1 (D8/D9, validés) —
 * journal unique servant à la fois d'anti-duplication et d'audit des
 * envois (email externe client/fournisseur, digest interne stock bas).
 * Point d'entrée UNIQUE de création : reserve() — même convention que
 * tout le reste de ce projet (Invoice::generateFromSalesOrder(),
 * InvoicePayment::recordFor(), CreditNoteLineReturn::recordFor()...).
 *
 * Volontairement PAS immuable après création (contrairement à
 * Invoice/CreditNote/InvoicePayment) : ce journal doit pouvoir
 * transitionner queued -> sent|failed, ce n'est pas un document légal.
 */
class NotificationLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * Réserve une entrée de journal pour un événement donné, AVANT toute
     * tentative d'envoi — jamais après (D8 : la réservation est la
     * garde elle-même, pas une trace a posteriori).
     *
     * D8 (validé) — retourne null si cet événement a déjà été notifié
     * (violation de la contrainte UNIQUE en base), jamais une exception
     * remontée à l'appelant : un doublon détecté ici doit toujours se
     * traduire par un envoi silencieusement ignoré, jamais par un échec
     * de la génération du document source (Invoice/CreditNote/retour).
     *
     * $recipientAddress null (aucune adresse connue, ex. Customer.email
     * vide) : la ligne est créée directement au statut 'failed' — la
     * seule action de l'appelant doit alors être de NE PAS mettre
     * l'email en file (jamais un Mail::to(null)).
     */
    public static function reserve(
        Model $notifiable,
        string $eventType,
        string $channel,
        ?string $recipientAddress,
        string $occurrenceKey = 'once',
    ): ?self {
        try {
            return static::create([
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'event_type' => $eventType,
                'channel' => $channel,
                'recipient_address' => $recipientAddress,
                'occurrence_key' => $occurrenceKey,
                'status' => $recipientAddress !== null ? self::STATUS_QUEUED : self::STATUS_FAILED,
                'error_message' => $recipientAddress !== null
                    ? null
                    : 'Aucune adresse connue pour ce destinataire.',
                'triggered_by_user_id' => auth()->id(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                // Déjà notifié (D8, validé) — jamais un doublon, jamais
                // une exception remontée à l'appelant.
                return null;
            }

            throw $e;
        }
    }

    /**
     * D5 (validé, "best-effort") — marqué au plus près de la remise au
     * transport, jamais une confirmation de délivrance réelle (cf.
     * app/Mail/*::content()). Corrigible ensuite par markAsFailed() si
     * l'envoi échoue réellement après ce point (cf. app/Mail/*::failed()).
     */
    public function markAsSent(): void
    {
        $this->update(['status' => self::STATUS_SENT, 'sent_at' => now()]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update(['status' => self::STATUS_FAILED, 'error_message' => $errorMessage]);
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
