<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Étape T23 (contrainte 9) — génère le PDF EXCLUSIVEMENT depuis les
 * données déjà figées de Invoice/InvoiceLine (jamais depuis
 * SalesOrder/Customer/CompanySettings) : un appel répété à cette route
 * produit toujours un document strictement identique, quels que soient
 * les changements survenus depuis sur le client/l'entreprise/le
 * catalogue.
 *
 * Autorisation : réutilise InvoiceResource::canView() telle quelle —
 * même principe que VtcRideReceiptController (route HTTP classique,
 * hors du scoping automatique de Filament, donc revérifiée
 * explicitement ici). D4 : aucun scoping entrepôt, les mêmes règles
 * que SalesOrderResource s'appliquent (BlocksChauffeurReadAccess
 * uniquement — un chauffeur reçoit 404, tout le reste peut consulter).
 */
class InvoicePdfController extends Controller
{
    public function show(Invoice $invoice): Response
    {
        abort_unless(InvoiceResource::canView($invoice), 404);

        $invoice->loadMissing('lines');

        return Pdf::loadView('invoices.pdf', ['invoice' => $invoice])
            ->setPaper('a4')
            ->stream("facture-{$invoice->number}.pdf");
    }
}
