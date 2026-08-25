<?php

namespace App\Filament\Resources\SalesOrders\Pages\Concerns;

use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Les 3 actions de transition de statut d'une commande de vente,
 * partagées entre EditSalesOrder et ViewSalesOrder. Miroir de
 * HasPurchaseOrderWorkflowActions (côté achats), avec la même approche :
 * la validation métier vit entièrement dans le modèle
 * (SalesOrder::markAsConfirmed/cancel/ship), l'action se contente
 * d'afficher le résultat en notification plutôt que de laisser remonter
 * une exception brute.
 *
 * Étape T19 — les options du Select d'entrepôt de shipOrderAction sont
 * restreintes au périmètre de l'utilisateur courant
 * (ScopesToOwnWarehouses) : simple confort d'UI, la barrière autoritaire
 * reste ValidatesOperationWarehouse::assertValidOperationWarehouse(),
 * appelée depuis SalesOrder::ship().
 */
trait HasSalesOrderWorkflowActions
{
    use ScopesToOwnWarehouses;

    protected function confirmOrderAction(): Action
    {
        return Action::make('confirmOrder')
            ->label('Confirmer la commande')
            ->icon('heroicon-o-check-circle')
            ->color('info')
            ->visible(fn (SalesOrder $record): bool => $record->status === SalesOrder::STATUS_DRAFT)
            ->requiresConfirmation()
            ->action(function (SalesOrder $record) {
                try {
                    $record->markAsConfirmed();

                    Notification::make()
                        ->title('Commande confirmée')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Action impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function cancelOrderAction(): Action
    {
        return Action::make('cancelOrder')
            ->label('Annuler')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (SalesOrder $record): bool => in_array(
                $record->status,
                [SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED],
                true
            ))
            ->requiresConfirmation()
            ->action(function (SalesOrder $record) {
                try {
                    $record->cancel();

                    Notification::make()
                        ->title('Commande annulée')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Action impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function shipOrderAction(): Action
    {
        return Action::make('shipOrder')
            ->label('Expédier')
            ->icon('heroicon-o-truck')
            ->color('success')
            ->visible(fn (SalesOrder $record): bool => in_array(
                $record->status,
                [SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_SHIPPED],
                true
            ))
            ->schema(function (SalesOrder $record): array {
                // Étape T13 — un seul entrepôt pour toute l'expédition
                // (jamais par ligne). Pré-rempli uniquement s'il n'y a
                // aucune ambiguïté réelle (0 ou 1 entrepôt actif) :
                // dès que 2 entrepôts actifs ou plus existent, aucun
                // n'est choisi silencieusement (cf.
                // ValidatesOperationWarehouse::assertValidOperationWarehouse(),
                // barrière autoritaire côté modèle — ce pré-remplissage
                // n'est qu'un confort d'UI, jamais la seule protection).
                $activeWarehouses = collect(static::activeWarehousesOptionsForCurrentUser());

                $warehouseField = Select::make('warehouse_id')
                    ->label('Entrepôt d\'expédition')
                    ->options($activeWarehouses->toArray())
                    ->default($activeWarehouses->count() <= 1 ? $activeWarehouses->keys()->first() : null)
                    ->searchable()
                    ->preload()
                    ->required();

                $itemFields = $record->items()
                    ->get()
                    ->filter(fn (SalesOrderItem $item): bool => $item->quantity_shipped < $item->quantity_ordered)
                    ->map(function (SalesOrderItem $item) {
                        $remaining = $item->quantity_ordered - $item->quantity_shipped;
                        $label = $item->product?->nom ?? 'Produit supprimé';

                        if ($item->productVariant) {
                            $details = implode(' / ', array_filter([
                                $item->productVariant->size,
                                $item->productVariant->color,
                            ]));

                            if ($details !== '') {
                                $label .= " ({$details})";
                            }
                        }

                        return TextInput::make("shipped.{$item->id}")
                            ->label("{$label} — restant à expédier : {$remaining}")
                            ->numeric()
                            ->default($remaining)
                            ->minValue(0)
                            ->maxValue($remaining)
                            ->required();
                    })
                    ->values()
                    ->all();

                return [$warehouseField, ...$itemFields];
            })
            ->action(function (SalesOrder $record, array $data) {
                try {
                    $record->ship($data['shipped'] ?? [], $data['warehouse_id'] ?? null);

                    Notification::make()
                        ->title('Expédition enregistrée')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Expédition impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
