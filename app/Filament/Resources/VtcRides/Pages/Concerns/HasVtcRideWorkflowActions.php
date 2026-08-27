<?php

namespace App\Filament\Resources\VtcRides\Pages\Concerns;

use App\Filament\Resources\VtcRides\VtcRideResource;
use App\Models\Invoice;
use App\Models\VtcRide;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Actions de transition de statut d'une course, partagées entre
 * EditVtcRide et ViewVtcRide. Chacune délègue entièrement la validation
 * métier au modèle (VtcRide::markAsConfirmed()/cancel()) et se contente
 * d'afficher le résultat sous forme de notification Filament plutôt que
 * de laisser remonter une exception brute. Aucune règle de confirmation
 * ni d'annulation n'est dupliquée ici.
 *
 * Étape T26 — ->visible() ne bloque que l'affichage du bouton : un appel
 * Livewire direct/forgé pouvait contourner ces deux actions. ->authorize()
 * réutilise VtcRideResource::canEdit($record) — jamais un simple
 * admin/manager : c'est la seule règle qui reflète correctement "admin/
 * manager, ou le chauffeur propriétaire sur sa propre course en
 * brouillon" (étape 5.5) ; une garde calquée sur HasRoleBasedAuthorization
 * retirerait à tort ce droit légitime au chauffeur. Contrairement à
 * StockTransfer/SalesOrder/PurchaseOrder/Invoice/CreditNote (T25/T26),
 * le risque réel de contournement reste ici quasi nul : canView()/
 * canEdit() filtrent déjà par propriété d'enregistrement en amont, un
 * chauffeur ne peut forger un appel que sur SA PROPRE course brouillon —
 * exactement ce que canEdit() lui autorise déjà. Ajoutée par cohérence
 * architecturale (défense en profondeur), pas pour combler une escalade
 * de privilège démontrée.
 */
trait HasVtcRideWorkflowActions
{
    protected function confirmRideAction(): Action
    {
        return Action::make('confirmRide')
            ->label('Confirmer la course')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (VtcRide $record): bool => $record->status === VtcRide::STATUS_DRAFT)
            ->authorize(fn (VtcRide $record): bool => VtcRideResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Vous n'êtes pas autorisé à effectuer cette action sur cette course.")
            ->requiresConfirmation()
            ->action(function (VtcRide $record) {
                try {
                    $record->markAsConfirmed();

                    Notification::make()
                        ->title('Course confirmée')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Confirmation impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Étape 5.7 — annulation. Contrairement à cancelOrderAction() côté
     * PurchaseOrder/SalesOrder (visible depuis plusieurs statuts),
     * VtcRide::cancel() n'autorise la transition que depuis 'draft'
     * (cf. le modèle) : visible() reflète exactement cette même
     * restriction, pour ne jamais proposer une action que le modèle
     * refuserait de toute façon.
     */
    protected function cancelRideAction(): Action
    {
        return Action::make('cancelRide')
            ->label('Annuler')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (VtcRide $record): bool => $record->status === VtcRide::STATUS_DRAFT)
            ->authorize(fn (VtcRide $record): bool => VtcRideResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Vous n'êtes pas autorisé à effectuer cette action sur cette course.")
            ->requiresConfirmation()
            ->action(function (VtcRide $record) {
                try {
                    $record->cancel();

                    Notification::make()
                        ->title('Course annulée')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Annulation impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Étape 5.9 — reçu récapitulatif imprimable, uniquement pour une
     * course confirmée (le reçu n'a de sens que sur des montants
     * définitifs). Simple lien vers une route dédiée
     * (VtcRideReceiptController) plutôt qu'un ->action() : rien à
     * valider côté serveur au clic, la garde d'accès vit dans le
     * contrôleur (qui réutilise VtcRideResource::canView()).
     *
     * Chantier "facturation légale VTC" (D8, validé) — CONSERVÉ tel
     * quel, sans aucune condition supplémentaire liée à l'existence
     * d'une facture : la facturation n'est pas systématique (course
     * comptant/B2C vs compte facturé), le reçu (non légal) reste donc
     * toujours disponible pour toute course confirmée, en complément —
     * jamais en remplacement — de generateInvoiceAction()/
     * downloadInvoiceAction() ci-dessous.
     */
    protected function receiptAction(): Action
    {
        return Action::make('receipt')
            ->label('Reçu')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn (VtcRide $record): bool => $record->status === VtcRide::STATUS_CONFIRMED)
            ->url(fn (VtcRide $record): string => route('vtc-rides.receipt', $record))
            ->openUrlInNewTab();
    }

    /**
     * Chantier "facturation légale VTC" (D5, validé) — génération de la
     * facture légale, miroir exact de
     * HasSalesOrderWorkflowActions::generateInvoiceAction() (T23) :
     * visible uniquement pour une course confirmée sans facture déjà
     * émise (confort d'UI, jamais la seule protection — cf.
     * Invoice::generateFromVtcRide(), qui revérifie tout côté serveur).
     * Gardée explicitement par VtcRideResource::canEdit() : émettre un
     * document légal immuable est jugé trop sensible pour reposer sur
     * la seule protection indirecte de l'accès à la page — même
     * principe que côté vente. Un chauffeur, qui peut éditer SES
     * courses en brouillon (canEdit()), n'a jamais accès à cette action
     * de toute façon : elle n'est visible que pour une course confirmée,
     * jamais pour un brouillon.
     */
    protected function generateInvoiceAction(): Action
    {
        return Action::make('generateInvoice')
            ->label('Générer la facture')
            ->icon('heroicon-o-document-text')
            ->color('success')
            ->visible(fn (VtcRide $record): bool => VtcRideResource::canEdit($record)
                && $record->status === VtcRide::STATUS_CONFIRMED
                && ! Invoice::where('vtc_ride_id', $record->id)->exists())
            ->authorize(fn (VtcRide $record): bool => VtcRideResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
            ->requiresConfirmation()
            ->action(function (VtcRide $record) {
                try {
                    $invoice = Invoice::generateFromVtcRide($record);

                    Notification::make()
                        ->title("Facture {$invoice->number} générée")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Génération de facture impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Chantier "facturation légale VTC" (D5/D7, validé) — miroir exact
     * de HasSalesOrderWorkflowActions::downloadInvoiceAction() : visible
     * dès qu'une facture existe pour cette course, ouvre le PDF
     * (réutilisation stricte de la route et du contrôleur T23, agnostique
     * de l'origine de la facture) dans un nouvel onglet.
     */
    protected function downloadInvoiceAction(): Action
    {
        return Action::make('downloadInvoice')
            ->label('Télécharger la facture')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (VtcRide $record): bool => Invoice::where('vtc_ride_id', $record->id)->exists())
            ->url(function (VtcRide $record): ?string {
                $invoice = Invoice::where('vtc_ride_id', $record->id)->first();

                return $invoice ? route('invoices.pdf', $invoice) : null;
            })
            ->openUrlInNewTab();
    }
}
