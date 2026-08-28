<?php

namespace App\Mail;

use App\Models\CompanySettings;
use App\Models\CreditNoteLineReturn;
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
 * envoyé au client à l'enregistrement d'un bon de retour physique. PDF
 * joint réutilisé depuis la même vue que
 * CreditNoteLineReturnPdfController (chantier "bon de retour").
 */
class CustomerReturnIssuedMail extends Mailable implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600, 14400];

    public function __construct(
        public readonly CreditNoteLineReturn $return,
        public readonly int $notificationLogId,
    ) {
        $this->return->loadMissing('creditNoteLine.creditNote');
    }

    public function envelope(): Envelope
    {
        $creditNote = $this->return->creditNoteLine->creditNote;

        return new Envelope(
            subject: "Confirmation de retour — avoir {$creditNote->number}",
        );
    }

    public function content(): Content
    {
        NotificationLog::find($this->notificationLogId)?->markAsSent();

        return new Content(
            view: 'emails.customer-return-issued',
            with: [
                'return' => $this->return,
                'creditNote' => $this->return->creditNoteLine->creditNote,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $creditNote = $this->return->creditNoteLine->creditNote;

        $pdf = Pdf::loadView('credit_note_line_returns.pdf', [
            'return' => $this->return,
            'creditNote' => $creditNote,
            'company' => CompanySettings::current(),
        ])->setPaper('a4');

        return [
            Attachment::fromData(fn () => $pdf->output(), "bon-retour-avoir-{$creditNote->number}-{$this->return->id}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    public function failed(Throwable $e): void
    {
        NotificationLog::find($this->notificationLogId)?->markAsFailed($e->getMessage());
    }
}
