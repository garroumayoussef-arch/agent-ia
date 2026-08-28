<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\NotificationLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Chantier "Notifications & communication" V1 (D1/D4, validés) — email
 * envoyé au client à l'émission d'une facture, vente OU VTC (agnostique
 * de l'origine, D1 du chantier VTC). PDF joint STRICTEMENT réutilisé
 * depuis la même vue que InvoicePdfController (T23) — jamais un second
 * template, jamais un recalcul depuis SalesOrder/VtcRide/Customer.
 *
 * ShouldQueueAfterCommit (D12, validé) — la mise en file n'a lieu
 * qu'APRÈS le commit réussi de la transaction de génération de la
 * facture : Laravel diffère automatiquement le push tant qu'une
 * transaction englobante est ouverte, et ANNULE le push si elle échoue.
 * Défense en profondeur : Invoice::generateFromSalesOrder()/
 * generateFromVtcRide() ne dispatchent de toute façon qu'après que
 * DB::transaction() a déjà retourné (donc déjà committée) — les deux
 * mécanismes se cumulent, jamais l'un à la place de l'autre.
 *
 * D5 (validé, "best-effort") — statut 'sent' marqué dans content(), au
 * plus près de la remise au transport (jamais une confirmation de
 * délivrance réelle) ; 'failed' corrigé par failed() ci-dessous, hook
 * natif de Illuminate\Mail\SendQueuedMailable, appelé après épuisement
 * de $tries — jamais un job personnalisé.
 */
class InvoiceIssuedMail extends Mailable implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600, 14400];

    public function __construct(
        public readonly Invoice $invoice,
        public readonly int $notificationLogId,
    ) {
        $this->invoice->loadMissing('lines');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Facture {$this->invoice->number} — {$this->invoice->seller_legal_name}",
        );
    }

    public function content(): Content
    {
        // D5 (validé, best-effort) — cf. documentation de tête de classe.
        NotificationLog::find($this->notificationLogId)?->markAsSent();

        return new Content(
            view: 'emails.invoice-issued',
            with: ['invoice' => $this->invoice],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $this->invoice])->setPaper('a4');

        return [
            Attachment::fromData(fn () => $pdf->output(), "facture-{$this->invoice->number}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    /**
     * Hook natif Illuminate\Mail\SendQueuedMailable::failed() — appelé
     * après épuisement de $tries, jamais après chaque tentative
     * individuelle. Corrige le statut optimiste posé dans content()
     * ci-dessus au statut réellement final.
     */
    public function failed(Throwable $e): void
    {
        NotificationLog::find($this->notificationLogId)?->markAsFailed($e->getMessage());
    }
}
