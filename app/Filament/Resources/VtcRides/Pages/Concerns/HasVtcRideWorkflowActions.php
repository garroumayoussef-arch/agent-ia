<?php

namespace App\Filament\Resources\VtcRides\Pages\Concerns;

use App\Models\VtcRide;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Action de confirmation d'une course, partagée entre EditVtcRide et
 * ViewVtcRide. Délègue entièrement la validation métier au modèle
 * (VtcRide::markAsConfirmed() — driver/vehicle/montant/taux résolu) et
 * se contente d'afficher le résultat sous forme de notification
 * Filament plutôt que de laisser remonter une exception brute. Aucune
 * règle de confirmation n'est dupliquée ici.
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
}
