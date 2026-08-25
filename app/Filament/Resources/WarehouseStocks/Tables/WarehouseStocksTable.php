<?php

namespace App\Filament\Resources\WarehouseStocks\Tables;

use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Models\Warehouse;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Étape T14 — table strictement en lecture : aucune colonne d'action
 * (pas de recordActions, pas de toolbarActions) — cohérent avec
 * WarehouseStockResource, qui ne déclare aucune page create/edit.
 *
 * Étape T20 — les options du filtre warehouse_id sont intersectées
 * avec le périmètre de l'utilisateur courant : simple confort d'UI
 * (éviter de proposer un entrepôt qui ne retournerait de toute façon
 * aucune ligne), jamais la barrière elle-même — celle-ci reste
 * WarehouseStockResource::getEloquentQuery(). Comportement préservé à
 * l'identique pour tout utilisateur non restreint : la liste inclut
 * toujours les entrepôts inactifs, comme avant T20.
 */
class WarehouseStocksTable
{
    use ScopesToOwnWarehouses;
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
                    ->options(function () {
                        $allowedWarehouseIds = static::currentUserWarehouseIds();

                        return Warehouse::query()
                            ->when(
                                $allowedWarehouseIds !== null,
                                fn ($query) => $query->whereIn('id', $allowedWarehouseIds),
                            )
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray();
                    }),
            ]);
    }
}
