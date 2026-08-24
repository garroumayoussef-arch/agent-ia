<?php

namespace App\Filament\Resources\VtcRides\Pages\Concerns;

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
}
