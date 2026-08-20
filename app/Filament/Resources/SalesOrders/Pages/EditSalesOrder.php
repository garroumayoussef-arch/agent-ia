<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Filament\Resources\SalesOrders\Pages\Concerns\HasSalesOrderWorkflowActions;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesOrder;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    use HasSalesOrderWorkflowActions;

    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmOrderAction(),
            $this->shipOrderAction(),
            $this->cancelOrderAction(),
            DeleteAction::make()
                // Le modèle refuse la suppression d'une commande dont au
                // moins une ligne a été expédiée (cf. SalesOrder::deleting).
                ->action(function (SalesOrder $record) {
                    try {
                        $record->delete();

                        Notification::make()
                            ->title('Commande supprimée')
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
