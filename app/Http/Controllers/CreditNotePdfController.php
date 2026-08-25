<?php

namespace App\Http\Controllers;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Étape T24 — génère le PDF EXCLUSIVEMENT depuis les données déjà
 * figées de CreditNote/CreditNoteLine (jamais depuis Invoice/Customer/
 * CompanySettings) — même principe que InvoicePdfController (T23).
 *
 * Autorisation : réutilise CreditNoteResource::canView() — même
 * pattern que InvoicePdfController/VtcRideReceiptController (route HTTP
 * classique, hors du scoping automatique de Filament, donc revérifiée
 * explicitement ici).
 */
class CreditNotePdfController extends Controller
{
    public function show(CreditNote $creditNote): Response
    {
        abort_unless(CreditNoteResource::canView($creditNote), 404);

        $creditNote->loadMissing('lines');

        return Pdf::loadView('credit_notes.pdf', ['creditNote' => $creditNote])
            ->setPaper('a4')
            ->stream("avoir-{$creditNote->number}.pdf");
    }
}
