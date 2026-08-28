<?php

namespace App\Mail;

use App\Models\CompanySettings;
use App\Models\NotificationLog;
use App\Models\PurchaseOrderItemReturn;
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
 * envoyé au fournisseur à l'enregistrement d'un retour physique de
 * marchandise. PDF joint réutilisé depuis la même vue que
 * PurchaseOrderItemReturnPdfController (chantier "bon de retour").
 */
class SupplierReturnIssuedMail extends Mailable implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600, 14400];

    public function __construct(
        public readonly PurchaseOrderItemReturn $return,
        public readonly int $notificationLogId,
    ) {
        $this->return->loadMissing('purchaseOrderItem.purchaseOrder.supplier');
    }

    public function envelope(): Envelope
    {
        $purchaseOrder = $this->return->purchaseOrderItem->purchaseOrder;

        return new Envelope(
            subject: "Retour marchandise — bon de commande {$purchaseOrder->reference}",
        );
    }

    public function content(): Content
    {
        NotificationLog::find($this->notificationLogId)?->markAsSent();

        return new Content(
            view: 'emails.supplier-return-issued',
            with: [
                'return' => $this->return,
                'purchaseOrder' => $this->return->purchaseOrderItem->purchaseOrder,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $purchaseOrder = $this->return->purchaseOrderItem->purchaseOrder;

        $pdf = Pdf::loadView('purchase_order_item_returns.pdf', [
            'return' => $this->return,
            'purchaseOrder' => $purchaseOrder,
            'company' => CompanySettings::current(),
        ])->setPaper('a4');

        return [
            Attachment::fromData(fn () => $pdf->output(), "bon-retour-fournisseur-{$purchaseOrder->reference}-{$this->return->id}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    public function failed(Throwable $e): void
    {
        NotificationLog::find($this->notificationLogId)?->markAsFailed($e->getMessage());
    }
}
