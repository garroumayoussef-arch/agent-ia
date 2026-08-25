<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Models\Product;
use App\Models\Warehouse;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Étape T20 — les options du filtre warehouse_id sont intersectées
 * avec le périmètre de l'utilisateur courant : simple confort d'UI,
 * jamais la barrière elle-même — celle-ci reste
 * StockMovementResource::getEloquentQuery(). Comportement préservé à
 * l'identique pour tout utilisateur non restreint.
 */
class StockMovementsTable
{
    use ScopesToOwnWarehouses;
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.nom')
                    ->label('Produit')
                    ->searchable(),

                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label('Entrepôt')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'purchase' => '🟢 Achat',
                        'sale' => '🔴 Vente',
                        'return' => '🟡 Retour',
                        'adjustment' => '🟠 Ajustement',
                        'inventory' => '⚪ Inventaire',
                        'transfer_out' => '📤 Transfert sortant',
                        'transfer_in' => '📥 Transfert entrant',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'purchase', 'return' => 'success',
                        'sale' => 'danger',
                        'adjustment' => 'warning',
                        'transfer_out', 'transfer_in' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantité'),

                Tables\Columns\TextColumn::make('stock_before')
                    ->label('Stock avant'),

                Tables\Columns\TextColumn::make('stock_after')
                    ->label('Stock après'),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type de mouvement')
                    ->options([
                        'purchase' => '🟢 Achat',
                        'sale' => '🔴 Vente',
                        'return' => '🟡 Retour',
                        'adjustment' => '🟠 Ajustement',
                        'inventory' => '⚪ Inventaire',
                    ]),

                SelectFilter::make('product_id')
                    ->label('Produit')
                    ->options(fn () => Product::query()->orderBy('nom')->pluck('nom', 'id')->toArray())
                    ->searchable(),

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
                    })
                    ->searchable(),

                Filter::make('created_at')
                    ->label('Période')
                    ->schema([
                        DatePicker::make('from')
                            ->label('Du'),
                        DatePicker::make('until')
                            ->label('Au'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Du '.Carbon::parse($data['from'])->format('d/m/Y');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Au '.Carbon::parse($data['until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}