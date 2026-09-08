<?php

namespace App\Filament\Resources\PurchaseOrders\Pages\Concerns;

use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderItemReturn;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Collection;

/**
 * Chantier "retour physique fournisseur" (décisions 1 à 6, validées) —
 * action de déclaration d'un retour physique vers le fournisseur,
 * entièrement SÉPARÉE et indépendante de SupplierCreditNote (décision 1 :
 * aucun lien, aucun champ de rattachement). Un PurchaseOrder peut
 * recevoir 0, 1, ou plusieurs retours successifs, sur une ou plusieurs
 * de ses lignes réceptionnées.
 *
 * Gardée par PurchaseOrderResource::canEdit() (admin/manager) — même
 * convention que HasPurchaseOrderWorkflowActions/HasCreditNoteReturnAction :
 * ->authorize() est réellement évaluée côté serveur à chaque montage/
 * exécution de l'action (leçon T25-B), ->visible() ne fait que masquer
 * le bouton.
 *
 * Toute la validation métier (quantité > 0, cumul jamais supérieur à
 * quantity_received, entrepôt valide) est déléguée intégralement à
 * PurchaseOrderItemReturn::recordFor() — jamais confiance dans la
 * sélection venue du navigateur.
 *
 * Décision 3 (validée) : aucune distinction de condition — le formulaire
 * ne propose qu'un motif libre optionnel (`reason`), sans effet sur le
 * StockMovement généré (systématique).
 *
 * Décision 5 (validée) : sélection d'entrepôt identique à
 * receiveOrderAction (HasPurchaseOrderWorkflowActions) — un seul
 * entrepôt pour toute la déclaration, jamais choisi silencieusement dès
 * qu'une ambiguïté réelle existe.
 */
trait HasPurchaseOrderReturnAction
{
    use ScopesToOwnWarehouses;

    protected function recordPurchaseOrderReturnAction(): Action
    {
        return Action::make('recordPurchaseOrderReturn')
            ->label('Déclarer un retour fournisseur')
            ->icon('heroicon-o-arrow-uturn-right')
            ->color('warning')
            ->visible(fn (PurchaseOrder $record): bool => PurchaseOrderResource::canEdit($record)
                && static::returnableItemsFor($record)->isNotEmpty())
            ->authorize(fn (PurchaseOrder $record): bool => PurchaseOrderResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage('Cette action est réservée aux administrateurs et gestionnaires.')
            ->requiresConfirmation()
            ->schema(function (PurchaseOrder $record): array {
                $returnableItems = static::returnableItemsFor($record);

                // Même confort d'UI que receiveOrderAction : pré-rempli
                // uniquement s'il n'y a aucune ambiguïté réelle (0 ou 1
                // entrepôt actif) — jamais la seule protection, cf.
                // PurchaseOrderItemReturn::recordFor() (barrière
                // autoritaire).
                $activeWarehouses = collect(static::activeWarehousesOptionsForCurrentUser());

                return [
                    Select::make('purchase_order_item_id')
                        ->label('Ligne concernée')
                        ->options($returnableItems->mapWithKeys(
                            fn (PurchaseOrderItem $item): array => [$item->id => static::returnItemLabel($item)]
                        ))
                        ->required()
                        ->live(),

                    TextInput::make('quantity')
                        ->label('Quantité retournée')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->helperText(function (Get $get) use ($returnableItems): ?string {
                            $selectedItemId = $get('purchase_order_item_id');

                            if (blank($selectedItemId)) {
                                return null;
                            }

                            $item = $returnableItems->firstWhere('id', (int) $selectedItemId);

                            if (! $item) {
                                return null;
                            }

                            $remaining = $item->quantity_received - PurchaseOrderItemReturn::totalReturnedFor($item);

                            return "Quantité maximale retournable pour cette ligne : {$remaining}.";
                        }),

                    Select::make('warehouse_id')
                        ->label('Entrepôt de départ')
                        ->options($activeWarehouses->toArray())
                        ->default($activeWarehouses->count() <= 1 ? $activeWarehouses->keys()->first() : null)
                        ->searchable()
                        ->preload()
                        ->required(),

                    DatePicker::make('returned_at')
                        ->label('Date du retour')
                        ->required()
                        ->default(now()->toDateString()),

                    TextInput::make('reference')
                        ->label('Référence (optionnelle)')
                        ->maxLength(255),

                    Textarea::make('reason')
                        ->label('Motif (optionnel)')
                        ->rows(2)
                        ->columnSpanFull(),

                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ];
            })
            ->action(function (array $data) {
                try {
                    $item = PurchaseOrderItem::findOrFail($data['purchase_order_item_id']);

                    $return = PurchaseOrderItemReturn::recordFor(
                        $item,
                        (int) $data['quantity'],
                        $data['returned_at'],
                        isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
                        $data['reason'] ?? null,
                        $data['reference'] ?? null,
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title('Retour fournisseur enregistré')
                        ->body("{$return->quantity} unité(s) retournée(s), stock décrémenté.")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Enregistrement du retour impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Lignes de CE bon de commande encore retournables — calculé à la
     * demande, jamais un champ stocké (même principe que
     * CreditNote::creditableLinesFor()). Une ligne est retournable dès
     * qu'elle a une quantité réceptionnée non encore intégralement
     * retournée (décision 4, validée : aucune limite liée au statut du
     * bon de commande).
     *
     * @return Collection<int, PurchaseOrderItem>
     */
    private static function returnableItemsFor(PurchaseOrder $record): Collection
    {
        return $record->items()->get()->filter(
            fn (PurchaseOrderItem $item): bool => $item->quantity_received > 0
                && PurchaseOrderItemReturn::totalReturnedFor($item) < $item->quantity_received
        )->values();
    }

    private static function returnItemLabel(PurchaseOrderItem $item): string
    {
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

        $alreadyReturned = PurchaseOrderItemReturn::totalReturnedFor($item);
        $label .= " — {$alreadyReturned}/{$item->quantity_received} déjà retourné(s)";

        return $label;
    }
}
