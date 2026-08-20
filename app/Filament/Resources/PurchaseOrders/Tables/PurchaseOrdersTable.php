<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
}
