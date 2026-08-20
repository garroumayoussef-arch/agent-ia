<?php

namespace App\Filament\Resources\PurchaseOrders\Pages\Concerns;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Les 3 actions de transition de statut d'un bon de commande, partagées
 * entre EditPurchaseOrder et ViewPurchaseOrder pour éviter de dupliquer
 * leur définition (schéma dynamique de l'action de réception compris).
 *
 * Chaque action délègue entièrement la validation métier au modèle
 * (PurchaseOrder::markAsOrdered/cancel/receive) et se contente
 * d'afficher le résultat sous forme de notification Filament plutôt que
 * de laisser remonter une exception brute.
 */
trait HasPurchaseOrderWorkflowActions
{
    protected function confirmOrderAction(): Action
    {
        return Action::make('confirmOrder')
            ->label('Confirmer la commande')
            ->icon('heroicon-o-check-circle')
            ->color('info')
            ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrder::STATUS_DRAFT)
            ->requiresConfirmation()
            ->action(function (PurchaseOrder $record) {
                try {
                    $record->markAsOrdered();

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
            ->visible(fn (PurchaseOrder $record): bool => in_array(
                $record->status,
                [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED],
                true
            ))
            ->requiresConfirmation()
            ->action(function (PurchaseOrder $record) {
                try {
                    $record->cancel();

                    Notification::make()
                        ->title('Bon de commande annulé')
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

    protected function receiveOrderAction(): Action
    {
        return Action::make('receiveOrder')
            ->label('Réceptionner')
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('success')
            ->visible(fn (PurchaseOrder $record): bool => in_array(
                $record->status,
                [PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED],
                true
            ))
            ->schema(fn (PurchaseOrder $record): array => $record->items()
                ->get()
                ->filter(fn (PurchaseOrderItem $item): bool => $item->quantity_received < $item->quantity_ordered)
                ->map(function (PurchaseOrderItem $item) {
                    $remaining = $item->quantity_ordered - $item->quantity_received;
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

                    return TextInput::make("received.{$item->id}")
                        ->label("{$label} — restant à recevoir : {$remaining}")
                        ->numeric()
                        ->default($remaining)
                        ->minValue(0)
                        ->maxValue($remaining)
                        ->required();
                })
                ->values()
                ->all())
            ->action(function (PurchaseOrder $record, array $data) {
                try {
                    $record->receive($data['received'] ?? []);

                    Notification::make()
                        ->title('Réception enregistrée')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Réception impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
