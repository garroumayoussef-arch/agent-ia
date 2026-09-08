<?php

namespace App\Filament\Resources\SalesOrders\Pages\Concerns;

use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\Invoice;
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
 *
 * Étape T23 — generateInvoiceAction()/downloadInvoiceAction() : la
 * génération de facture est explicitement gardée par
 * SalesOrderResource::canEdit() (admin/manager). C'est un choix
 * délibéré, à la différence des 3 actions ci-dessus (confirmOrder/
 * cancelOrder/shipOrder) qui n'ont, elles, aucune garde de rôle
 * explicite au niveau de l'action — leur seule protection vient de ce
 * que EditSalesOrder n'est atteignable que par un admin/manager
 * (canEdit()), alors que ViewSalesOrder (qui compose pourtant le même
 * trait) reste accessible à un viewer. Émettre un document légal
 * immuable est jugé trop sensible pour reposer sur cette seule
 * protection indirecte : la génération de facture porte donc sa propre
 * garde explicite, qu'elle apparaisse sur EditSalesOrder ou
 * ViewSalesOrder.
 *
 * Étape T25-C — ->visible() ne bloque que l'affichage du bouton :
 * Filament résout une action en appelant directement sa méthode PHP
 * (resolveAction()), sans jamais consulter isVisible() — un appel
 * Livewire direct/forgé pouvait donc jusqu'ici contourner toutes ces
 * actions, y compris confirmOrder/cancelOrder/shipOrder qui n'avaient
 * même pas de garde de rôle en ->visible(). ->authorize() est en
 * revanche réellement évalué côté serveur à chaque montage/exécution de
 * l'action (mountAction()/callMountedAction()), y compris un tel appel
 * direct : c'est la barrière ajoutée ici sur les 4 actions, en plus des
 * ->visible() existants qui restent inchangés (comportement d'affichage
 * identique à avant — seule l'exécution effective par un rôle non
 * autorisé est désormais bloquée). Cumulative avec
 * ValidatesOperationWarehouse (T19) sur shipOrderAction, jamais en
 * remplacement.
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
            ->authorize(fn (SalesOrder $record): bool => SalesOrderResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
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
            ->authorize(fn (SalesOrder $record): bool => SalesOrderResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
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
            ->authorize(fn (SalesOrder $record): bool => SalesOrderResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
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
                                $item->productVariant->attributeMirrorValue('size'),
                                $item->productVariant->attributeMirrorValue('color'),
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

    /**
     * Étape T23 (D2/contrainte 7) — visible UNIQUEMENT si la commande
     * est intégralement expédiée (STATUS_SHIPPED) : jamais pour
     * partially_shipped, aucune facturation partielle en V1. Invisible
     * dès qu'une facture existe déjà (Invoice::generateFromSalesOrder()
     * refuse de toute façon les doublons — cette condition n'est qu'un
     * confort d'UI, jamais la seule protection).
     *
     * Gardée explicitement par SalesOrderResource::canEdit() (voir le
     * commentaire de tête du trait) : jamais accessible à un viewer,
     * même depuis ViewSalesOrder.
     */
    protected function generateInvoiceAction(): Action
    {
        return Action::make('generateInvoice')
            ->label('Générer la facture')
            ->icon('heroicon-o-document-text')
            ->color('success')
            ->visible(fn (SalesOrder $record): bool => SalesOrderResource::canEdit($record)
                && $record->status === SalesOrder::STATUS_SHIPPED
                && ! Invoice::where('sales_order_id', $record->id)->exists())
            ->authorize(fn (SalesOrder $record): bool => SalesOrderResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
            ->requiresConfirmation()
            ->action(function (SalesOrder $record) {
                try {
                    $invoice = Invoice::generateFromSalesOrder($record);

                    Notification::make()
                        ->title("Facture {$invoice->number} générée")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Génération de facture impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Étape T23 — visible dès qu'une facture existe pour cette
     * commande. Ouvre le PDF (régénéré à chaque appel depuis les
     * données figées de Invoice/InvoiceLine, jamais depuis SalesOrder)
     * dans un nouvel onglet, imprimable/téléchargeable nativement par
     * le navigateur.
     */
    protected function downloadInvoiceAction(): Action
    {
        return Action::make('downloadInvoice')
            ->label('Télécharger la facture')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (SalesOrder $record): bool => Invoice::where('sales_order_id', $record->id)->exists())
            ->url(function (SalesOrder $record): ?string {
                $invoice = Invoice::where('sales_order_id', $record->id)->first();

                return $invoice ? route('invoices.pdf', $invoice) : null;
            })
            ->openUrlInNewTab();
    }
}
