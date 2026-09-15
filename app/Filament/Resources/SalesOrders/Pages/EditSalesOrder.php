<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Filament\Resources\SalesOrders\Concerns\HasSalesOrderSourcingAction;
use App\Filament\Resources\SalesOrders\Pages\Concerns\HasSalesOrderWorkflowActions;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesOrder;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    use HasSalesOrderWorkflowActions;
    use HasSalesOrderSourcingAction;

    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmOrderAction(),
            Action::make('allocateSourcing')
                ->label('Allouer le sourcing')
                ->icon('heroicon-o-cube')
                ->color('info')
                ->visible(fn (SalesOrder $record): bool => in_array(
                    $record->status,
                    [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_SHIPPED],
                    true
                ))
                ->authorize(fn (SalesOrder $record): bool => SalesOrderResource::canEdit($record))
                ->authorizationNotification()
                ->authorizationMessage('Cette action est réservée aux administrateurs et gestionnaires.')
                ->requiresConfirmation()
                ->action(function (SalesOrder $record) {
                    $result = $this->orchestrateSourcing($record);

                    $allocated = count($result['allocated']);
                    $skipped = count($result['skipped']);
                    $failed = count($result['failed']);

                    if ($allocated === 0 && $skipped === 0 && $failed === 0) {
                        Notification::make()->title('Aucune ligne à allouer')->info()->send();

                        return;
                    }

                    $parts = array_filter([
                        $allocated > 0 ? "{$allocated} ligne(s) allouée(s)" : null,
                        $skipped > 0 ? "{$skipped} déjà allouée(s)" : null,
                        $failed > 0 ? "{$failed} en échec" : null,
                    ]);

                    Notification::make()
                        ->title('Sourcing orchestré')
                        ->body(implode(', ', $parts).'.')
                        ->color($failed > 0 ? 'warning' : 'success')
                        ->send();
                }),
            $this->shipOrderAction(),
            $this->cancelOrderAction(),
            $this->generateInvoiceAction(),
            $this->downloadInvoiceAction(),
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
