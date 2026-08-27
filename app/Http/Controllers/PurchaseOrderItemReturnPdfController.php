<?php

namespace App\Http\Controllers;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\CompanySettings;
use App\Models\PurchaseOrderItemReturn;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chantier "bon de retour" (décisions 1 à 6, validées) — génère le PDF
 * d'un retour physique fournisseur, exclusivement depuis les données
 * déjà figées de PurchaseOrderItemReturn (produit/variante snapshotés
 * à l'enregistrement du retour, cf.
 * PurchaseOrderItemReturn::recordFor()) — jamais recalculé depuis
 * PurchaseOrderItem/Product/Supplier au moment du téléchargement, même
 * principe que CreditNoteLineReturnPdfController.
 *
 * Décision 2 (validée) : informations légales de l'entreprise lues en
 * direct depuis CompanySettings::current() à chaque génération, jamais
 * figées sur le retour — même choix que côté client.
 *
 * Décision 5 (validée) : contrôleur dédié, distinct de
 * CreditNoteLineReturnPdfController — aucun template conditionnel
 * partagé.
 *
 * Autorisation : réutilise PurchaseOrderResource::canView() sur le bon
 * de commande PARENT du retour — même pattern que
 * CreditNoteLineReturnPdfController.
 */
class PurchaseOrderItemReturnPdfController extends Controller
{
    public function show(PurchaseOrderItemReturn $purchaseOrderItemReturn): Response
    {
        $purchaseOrder = $purchaseOrderItemReturn->purchaseOrderItem->purchaseOrder;

        abort_unless(PurchaseOrderResource::canView($purchaseOrder), 404);

        $purchaseOrderItemReturn->loadMissing([
            'purchaseOrderItem.purchaseOrder.supplier',
            'product',
            'productVariant',
            'user',
            'stockMovement.warehouse',
        ]);

        return Pdf::loadView('purchase_order_item_returns.pdf', [
            'return' => $purchaseOrderItemReturn,
            'purchaseOrder' => $purchaseOrder,
            'company' => CompanySettings::current(),
        ])
            ->setPaper('a4')
            // Décision 3/6 (validées) : pas de séquence formelle dédiée —
            // le nom du fichier s'appuie sur la référence du bon de
            // commande parent et l'identifiant technique du retour.
            ->stream("bon-retour-fournisseur-{$purchaseOrder->reference}-{$purchaseOrderItemReturn->id}.pdf");
    }
}
