<?php

namespace App\Filament\Resources\WarehouseStocks\Tables;

use App\Models\Warehouse;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Étape T14 — table strictement en lecture : aucune colonne d'action
 * (pas de recordActions, pas de toolbarActions) — cohérent avec
 * WarehouseStockResource, qui ne déclare aucune page create/edit.
 */
class WarehouseStocksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('Entrepôt')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.nom')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('productVariant.sku')
                    ->label('Variante')
                    ->placeholder('-')
                    ->searchable()
                    ->formatStateUsing(function ($state, $record): string {
                        if (! $record->productVariant) {
                            return '-';
                        }

                        $parts = array_filter([
                            $record->productVariant->size,
                            $record->productVariant->color,
                        ]);

                        $label = implode(' / ', $parts);

                        if ($record->productVariant->sku) {
                            $label .= $label !== '' ? " — SKU : {$record->productVariant->sku}" : "SKU : {$record->productVariant->sku}";
                        }

                        return $label !== '' ? $label : '-';
                    }),

                TextColumn::make('stock')
                    ->label('Stock')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Mis à jour le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('Entrepôt')
                    ->options(fn () => Warehouse::query()->orderBy('name')->pluck('name', 'id')->toArray()),
            ]);
    }
}
