<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Chantier Dropshipping, Gap D — chargement explicite à
            // l'échelle de la liste (plusieurs bons de commande par
            // page), même principe que PurchaseOrderInfolist.php (Gap A)
            // qui ne traite qu'un seul enregistrement : sans ce eager
            // loading, originOverview() ci-dessous provoquerait un N+1
            // par ligne de chaque bon de commande affiché.
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'items.allocation.salesOrderItem.salesOrder',
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state))
                    ->color(fn (string $state): string => static::statusColor($state)),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Lignes')
                    ->counts('items'),

                // Chantier Dropshipping, Gap D — visibilité READ-ONLY,
                // dans la liste, de l'origine déjà calculée ligne par
                // ligne dans PurchaseOrderInfolist.php (origin, Gap A/
                // D2.8.1) : agrégation en un seul libellé par bon de
                // commande, y compris le cas mixte verrouillé (Gap D,
                // Q2) — ne réévalue aucune règle de sourcing, ne lit que
                // ce qui est déjà persisté par
                // CreatePurchaseOrdersFromAllocations (D2.6).
                Tables\Columns\TextColumn::make('origin_overview')
                    ->label('Origine')
                    ->state(fn (PurchaseOrder $record): string => static::originOverview($record)),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Date de commande')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        PurchaseOrder::STATUS_DRAFT => static::statusLabel(PurchaseOrder::STATUS_DRAFT),
                        PurchaseOrder::STATUS_ORDERED => static::statusLabel(PurchaseOrder::STATUS_ORDERED),
                        PurchaseOrder::STATUS_PARTIALLY_RECEIVED => static::statusLabel(PurchaseOrder::STATUS_PARTIALLY_RECEIVED),
                        PurchaseOrder::STATUS_RECEIVED => static::statusLabel(PurchaseOrder::STATUS_RECEIVED),
                        PurchaseOrder::STATUS_CANCELLED => static::statusLabel(PurchaseOrder::STATUS_CANCELLED),
                    ]),

                SelectFilter::make('supplier_id')
                    ->label('Fournisseur')
                    ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    // Le modèle refuse la suppression d'un bon dont au
                    // moins une ligne a été réceptionnée (cf.
                    // PurchaseOrder::deleting) : on affiche une
                    // notification plutôt qu'une erreur brute.
                    ->action(function (PurchaseOrder $record) {
                        try {
                            $record->delete();

                            Notification::make()
                                ->title('Bon de commande supprimé')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Suppression impossible')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            PurchaseOrder::STATUS_DRAFT => 'Brouillon',
            PurchaseOrder::STATUS_ORDERED => 'Commandé',
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED => 'Partiellement reçu',
            PurchaseOrder::STATUS_RECEIVED => 'Reçu',
            PurchaseOrder::STATUS_CANCELLED => 'Annulé',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            PurchaseOrder::STATUS_DRAFT => 'gray',
            PurchaseOrder::STATUS_ORDERED => 'info',
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED => 'warning',
            PurchaseOrder::STATUS_RECEIVED => 'success',
            PurchaseOrder::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }

    /**
     * Chantier Dropshipping, Gap D — origine agrégée (une seule valeur
     * par bon de commande) calculée sur l'ensemble des lignes déjà
     * chargées par modifyQueryUsing() ci-dessus. Le cas "plusieurs ventes
     * différentes sur le même bon" n'est atteignable par aucun chemin de
     * code actuel (CreatePurchaseOrdersFromAllocations scope toujours la
     * génération à une seule SalesOrder par appel et crée systématiquement
     * un nouveau PurchaseOrder) : ->first() ne masque donc aucun cas réel,
     * ce n'est pas une hypothèse défensive.
     */
    public static function originOverview(PurchaseOrder $record): string
    {
        $references = $record->items
            ->map(fn (PurchaseOrderItem $item) => $item->allocation?->salesOrderItem?->salesOrder?->reference)
            ->unique();

        $salesReferences = $references->filter()->values();
        $hasDirect = $references->contains(null);

        if ($salesReferences->isEmpty()) {
            return 'Achat direct';
        }

        if (! $hasDirect) {
            return "Vente {$salesReferences->first()}";
        }

        return "Vente {$salesReferences->first()} + achat direct";
    }
}
