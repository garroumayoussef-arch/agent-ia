<?php

namespace App\Filament\Resources\VtcRides\Pages;

use App\Filament\Resources\VtcRides\Pages\Concerns\HasVtcRideWorkflowActions;
use App\Filament\Resources\VtcRides\VtcRideResource;
use App\Models\VtcRide;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditVtcRide extends EditRecord
{
    use HasVtcRideWorkflowActions;

    protected static string $resource = VtcRideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmRideAction(),
            $this->cancelRideAction(),
            ViewAction::make(),
            DeleteAction::make()
                // Le modèle refuse la suppression d'une course qui
                // n'est plus en brouillon (cf. VtcRide::deleting).
                ->action(function (VtcRide $record) {
                    try {
                        $record->delete();

                        Notification::make()
                            ->title('Course supprimée')
                            ->success()
                            ->send();

                        $this->redirect(static::getResource()::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Suppression impossible')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
