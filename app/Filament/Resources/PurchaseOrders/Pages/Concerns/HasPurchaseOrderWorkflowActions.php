<?php

namespace App\Filament\Resources\PurchaseOrders\Pages\Concerns;

use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
 *
 * Étape T19 — les options du Select d'entrepôt de réceptionAction sont
 * restreintes au périmètre de l'utilisateur courant
 * (ScopesToOwnWarehouses) : simple confort d'UI, la barrière autoritaire
 * reste ValidatesOperationWarehouse::assertValidOperationWarehouse(),
 * appelée depuis PurchaseOrder::receive().
 *
 * Étape T25-C — aucune des 3 actions ci-dessous n'avait de garde de
 * rôle en ->visible() (statut seul) : leur seule protection venait de
 * ce que EditPurchaseOrder n'est atteignable que par un admin/manager,
 * alors que ViewPurchaseOrder (qui compose pourtant le même trait)
 * reste accessible à un viewer. Or ->visible() ne bloque de toute façon
 * que l'affichage du bouton — Filament résout une action en appelant
 * directement sa méthode PHP (resolveAction()), sans jamais consulter
 * isVisible() — un appel Livewire direct/forgé pouvait donc contourner
 * toutes ces actions. ->authorize() est réellement évalué côté serveur
 * à chaque montage/exécution de l'action (mountAction()/
 * callMountedAction()), y compris un tel appel direct : c'est la
 * barrière ajoutée ici, en plus des ->visible() existants qui restent
 * inchangés. Cumulative avec ValidatesOperationWarehouse (T19) sur
 * receiveOrderAction, jamais en remplacement.
 */
trait HasPurchaseOrderWorkflowActions
{
    use ScopesToOwnWarehouses;

    protected function confirmOrderAction(): Action
    {
        return Action::make('confirmOrder')
            ->label('Confirmer la commande')
            ->icon('heroicon-o-check-circle')
            ->color('info')
            ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrder::STATUS_DRAFT)
            ->authorize(fn (PurchaseOrder $record): bool => PurchaseOrderResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
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
            ->authorize(fn (PurchaseOrder $record): bool => PurchaseOrderResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
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
            ->authorize(fn (PurchaseOrder $record): bool => PurchaseOrderResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
            ->schema(function (PurchaseOrder $record): array {
                // Étape T13 — un seul entrepôt pour toute la réception
                // (jamais par ligne). Pré-rempli uniquement s'il n'y a
                // aucune ambiguïté réelle (0 ou 1 entrepôt actif) :
                // dès que 2 entrepôts actifs ou plus existent, aucun
                // n'est choisi silencieusement (cf.
                // ValidatesOperationWarehouse::assertValidOperationWarehouse(),
                // barrière autoritaire côté modèle — ce pré-remplissage
                // n'est qu'un confort d'UI, jamais la seule protection).
                $activeWarehouses = collect(static::activeWarehousesOptionsForCurrentUser());

                $warehouseField = Select::make('warehouse_id')
                    ->label('Entrepôt de réception')
                    ->options($activeWarehouses->toArray())
                    ->default($activeWarehouses->count() <= 1 ? $activeWarehouses->keys()->first() : null)
                    ->searchable()
                    ->preload()
                    ->required();

                $itemFields = $record->items()
                    ->get()
                    ->filter(fn (PurchaseOrderItem $item): bool => $item->quantity_received < $item->quantity_ordered)
                    ->map(function (PurchaseOrderItem $item) {
                        $remaining = $item->quantity_ordered - $item->quantity_received;
                        $label = $item->product?->nom ?? 'Produit supprimé';

                        if ($item->productVariant) {
                            $details = implode(' / ', array_filter([
                                $item->productVariant->attributeMirrorValue('size'),
                                $item->productVariant->attributeMirrorValue('color'),
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
                    ->all();

                return [$warehouseField, ...$itemFields];
            })
            ->action(function (PurchaseOrder $record, array $data) {
                try {
                    $record->receive($data['received'] ?? [], $data['warehouse_id'] ?? null);

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
