<?php

namespace App\Mail;

use App\Models\CreditNote;
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
 * envoyé au client à l'émission d'un avoir (vente OU VTC — un avoir sur
 * facture VTC fonctionne sans aucune modification de CreditNote, cf.
 * D11 du chantier VTC). PDF joint réutilisé depuis la même vue que
 * CreditNotePdfController (T24). Mêmes garanties que InvoiceIssuedMail
 * (ShouldQueueAfterCommit, statut best-effort, failed() natif).
 */
class CreditNoteIssuedMail extends Mailable implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600, 14400];

    public function __construct(
        public readonly CreditNote $creditNote,
        public readonly int $notificationLogId,
    ) {
        $this->creditNote->loadMissing('lines');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Avoir {$this->creditNote->number} — {$this->creditNote->seller_legal_name}",
        );
    }

    public function content(): Content
    {
        NotificationLog::find($this->notificationLogId)?->markAsSent();

        return new Content(
            view: 'emails.credit-note-issued',
            with: ['creditNote' => $this->creditNote],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = Pdf::loadView('credit_notes.pdf', ['creditNote' => $this->creditNote])->setPaper('a4');

        return [
            Attachment::fromData(fn () => $pdf->output(), "avoir-{$this->creditNote->number}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    public function failed(Throwable $e): void
    {
        NotificationLog::find($this->notificationLogId)?->markAsFailed($e->getMessage());
    }
}
