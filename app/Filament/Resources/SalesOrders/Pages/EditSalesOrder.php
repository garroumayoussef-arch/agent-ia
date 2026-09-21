<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Filament\Resources\SalesOrders\Concerns\HasSalesOrderCancelledPurchaseRecoveryAction;
use App\Filament\Resources\SalesOrders\Concerns\HasSalesOrderReallocationAction;
use App\Filament\Resources\SalesOrders\Concerns\HasSalesOrderSourcingAction;
use App\Filament\Resources\SalesOrders\Pages\Concerns\HasSalesOrderWorkflowActions;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesOrder;
use App\Models\SalesOrderItemAllocation;
use App\Services\CreatePurchaseOrdersFromAllocations;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    use HasSalesOrderWorkflowActions;
    use HasSalesOrderSourcingAction;
    use HasSalesOrderReallocationAction;
    use HasSalesOrderCancelledPurchaseRecoveryAction;

    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmOrderAction(),
            $this->cancelledPurchaseRecoveryAction(),
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
            Action::make('reallocateSourcing')
                ->label('Réallouer vers un autre fournisseur')
                ->icon('heroicon-o-arrow-path')
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
                    $result = $this->orchestrateReallocation($record);

                    $reallocated = count($result['reallocated']);
                    $skipped = count($result['skipped']);
                    $failed = count($result['failed']);

                    if ($reallocated === 0 && $failed === 0) {
                        Notification::make()->title('Aucune ligne éligible à la ré-allocation')->info()->send();

                        return;
                    }

                    $parts = array_filter([
                        $reallocated > 0 ? "{$reallocated} ligne(s) ré-allouée(s)" : null,
                        $skipped > 0 ? "{$skipped} non éligible(s)" : null,
                        $failed > 0 ? "{$failed} en échec" : null,
                    ]);

                    // Les messages d'échec sont affichés explicitement
                    // (spécification validée : information explicite à
                    // l'utilisateur, notamment "aucun fournisseur
                    // alternatif compatible"), jamais réduits à un
                    // simple compte contrairement à allocateSourcing/
                    // createPurchaseOrders.
                    $body = implode(', ', $parts).'.';

                    if ($failed > 0) {
                        $body .= ' '.implode(' ', array_unique($result['failed']));
                    }

                    Notification::make()
                        ->title('Ré-allocation traitée')
                        ->body($body)
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
