<?php

namespace App\Http\Controllers;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CompanySettings;
use App\Models\CreditNoteLineReturn;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chantier "bon de retour" (décisions 1 à 6, validées) — génère le PDF
 * d'un retour physique client, exclusivement depuis les données déjà
 * figées de CreditNoteLineReturn (produit/variante snapshotés à
 * l'enregistrement du retour, cf. CreditNoteLineReturn::recordFor()) —
 * jamais recalculé depuis CreditNoteLine/Product au moment du
 * téléchargement, même principe que InvoicePdfController (T23)/
 * CreditNotePdfController (T24).
 *
 * Décision 2 (validée) : contrairement à Invoice/CreditNote, les
 * informations légales de l'entreprise ne sont PAS figées sur le
 * retour — un bon de retour n'est pas une pièce fiscale soumise à la
 * même contrainte de reproductibilité stricte. Elles sont lues en
 * direct depuis CompanySettings::current() à chaque génération,
 * assumant qu'un changement ultérieur de ces informations se
 * répercute sur tous les bons de retour déjà émis.
 *
 * Décision 5 (validée) : contrôleur dédié, distinct de
 * PurchaseOrderItemReturnPdfController — aucun template conditionnel
 * partagé.
 *
 * Autorisation : réutilise CreditNoteResource::canView() sur l'avoir
 * PARENT du retour (même pattern que CreditNotePdfController) — route
 * HTTP classique, hors du scoping automatique de Filament, donc
 * revérifiée explicitement ici.
 */
class CreditNoteLineReturnPdfController extends Controller
{
    public function show(CreditNoteLineReturn $creditNoteLineReturn): Response
    {
        $creditNote = $creditNoteLineReturn->creditNoteLine->creditNote;

        abort_unless(CreditNoteResource::canView($creditNote), 404);

        $creditNoteLineReturn->loadMissing(['creditNoteLine.creditNote', 'product', 'productVariant', 'user']);

        return Pdf::loadView('credit_note_line_returns.pdf', [
            'return' => $creditNoteLineReturn,
            'creditNote' => $creditNote,
            'company' => CompanySettings::current(),
        ])
            ->setPaper('a4')
            // Décision 3/6 (validées) : pas de séquence formelle dédiée —
            // le nom du fichier s'appuie sur le numéro de l'avoir parent
            // (déjà une pièce numérotée) et l'identifiant technique du
            // retour lui-même.
            ->stream("bon-retour-avoir-{$creditNote->number}-{$creditNoteLineReturn->id}.pdf");
    }
}
