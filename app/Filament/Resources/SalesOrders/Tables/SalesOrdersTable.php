<?php

namespace App\Filament\Resources\SalesOrders\Tables;

use App\Models\Customer;
use App\Models\SalesOrder;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Client')
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
                        SalesOrder::STATUS_DRAFT => static::statusLabel(SalesOrder::STATUS_DRAFT),
                        SalesOrder::STATUS_CONFIRMED => static::statusLabel(SalesOrder::STATUS_CONFIRMED),
                        SalesOrder::STATUS_PARTIALLY_SHIPPED => static::statusLabel(SalesOrder::STATUS_PARTIALLY_SHIPPED),
                        SalesOrder::STATUS_SHIPPED => static::statusLabel(SalesOrder::STATUS_SHIPPED),
                        SalesOrder::STATUS_CANCELLED => static::statusLabel(SalesOrder::STATUS_CANCELLED),
                    ]),

                SelectFilter::make('customer_id')
                    ->label('Client')
                    ->options(fn () => Customer::query()->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    // Le modèle refuse la suppression d'une commande dont
                    // au moins une ligne a été expédiée (cf.
                    // SalesOrder::deleting) : on affiche une notification
                    // plutôt qu'une erreur brute.
                    ->action(function (SalesOrder $record) {
                        try {
                            $record->delete();

                            Notification::make()
                                ->title('Commande supprimée')
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
            SalesOrder::STATUS_DRAFT => 'Brouillon',
            SalesOrder::STATUS_CONFIRMED => 'Confirmée',
            SalesOrder::STATUS_PARTIALLY_SHIPPED => 'Partiellement expédiée',
            SalesOrder::STATUS_SHIPPED => 'Expédiée',
            SalesOrder::STATUS_CANCELLED => 'Annulée',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            SalesOrder::STATUS_DRAFT => 'gray',
            SalesOrder::STATUS_CONFIRMED => 'info',
            SalesOrder::STATUS_PARTIALLY_SHIPPED => 'warning',
            SalesOrder::STATUS_SHIPPED => 'success',
            SalesOrder::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }
}
