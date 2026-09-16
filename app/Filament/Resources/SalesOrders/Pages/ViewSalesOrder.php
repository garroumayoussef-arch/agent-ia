<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Filament\Resources\SalesOrders\Concerns\HasSalesOrderSourcingAction;
use App\Filament\Resources\SalesOrders\Pages\Concerns\HasSalesOrderWorkflowActions;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesOrder;
use App\Models\SalesOrderItemAllocation;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesOrder extends ViewRecord
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
            Action::make('createPurchaseOrders')
                ->label('Générer les commandes fournisseurs')
                ->icon('heroicon-o-document-plus')
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
                    // Deux requêtes au total (identifiants des lignes,
                    // puis leurs allocations en un seul whereIn), quel
                    // que soit le nombre de lignes : évite le N+1 qu'un
                    // accès direct à $item->allocation par ligne
                    // provoquerait. Retourne nativement une
                    // Eloquent\Collection<SalesOrderItemAllocation>,
                    // type exigé par execute() (contrairement à
                    // ->pluck(), qui renverrait une Support\Collection).
                    $allocations = SalesOrderItemAllocation::whereIn(
                        'sales_order_item_id',
                        $record->items()->pluck('id')
                    )->get();

                    $result = (new CreatePurchaseOrdersFromAllocations)->execute($allocations);

                    $created = count($result['created']);
                    $skipped = count($result['skipped']);
                    $failed = count($result['failed']);

                    if ($created === 0 && $skipped === 0 && $failed === 0) {
                        Notification::make()->title('Aucune allocation à convertir')->info()->send();

                        return;
                    }

                    $parts = array_filter([
                        $created > 0 ? "{$created} commande(s) fournisseur créée(s)" : null,
                        $skipped > 0 ? "{$skipped} déjà convertie(s)" : null,
                        $failed > 0 ? "{$failed} en échec" : null,
                    ]);

                    Notification::make()
                        ->title('Commandes fournisseurs générées')
                        ->body(implode(', ', $parts).'.')
                        ->color($failed > 0 ? 'warning' : 'success')
                        ->send();
                }),
            $this->shipOrderAction(),
            $this->cancelOrderAction(),
            $this->generateInvoiceAction(),
            $this->downloadInvoiceAction(),
            EditAction::make(),
        ];
    }
}
