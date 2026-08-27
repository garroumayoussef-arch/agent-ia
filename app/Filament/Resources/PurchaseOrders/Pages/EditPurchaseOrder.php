<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\Pages\Concerns\HasPurchaseOrderReturnAction;
use App\Filament\Resources\PurchaseOrders\Pages\Concerns\HasPurchaseOrderWorkflowActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseOrder extends EditRecord
{
    use HasPurchaseOrderWorkflowActions;
    use HasPurchaseOrderReturnAction;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmOrderAction(),
            $this->receiveOrderAction(),
            $this->cancelOrderAction(),
            // Chantier "retour physique fournisseur" — action séparée,
            // indépendante du cycle de statut ci-dessus (décision 4).
            $this->recordPurchaseOrderReturnAction(),
            DeleteAction::make()
                // Le modèle refuse la suppression d'un bon dont au moins
                // une ligne a été réceptionnée (cf. PurchaseOrder::deleting).
                ->action(function (PurchaseOrder $record) {
                    try {
                        $record->delete();

                        Notification::make()
                            ->title('Bon de commande supprimé')
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
