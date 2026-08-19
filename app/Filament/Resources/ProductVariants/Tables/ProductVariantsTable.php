<?php

namespace App\Filament\Resources\ProductVariants\Tables;

use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductVariantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.nom')
                    ->label('Produit')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('barcode')
                    ->label('Code-barres')
                    ->searchable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('size')
                    ->label('Taille')
                    ->placeholder('-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('color')
                    ->label('Couleur')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('version')
                    ->label('Version')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state <= 0 => 'danger',
                        $state <= 5 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('prix_vente')
                    ->label('Prix vente')
                    ->money('EUR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Actif',
                        'inactive' => 'Inactif',
                        'out_of_stock' => 'Rupture de stock',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'out_of_stock' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('product_id')
                    ->label('Produit')
                    ->options(fn () => Product::query()->orderBy('nom')->pluck('nom', 'id')->toArray())
                    ->searchable(),

                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'inactive' => 'Inactif',
                        'out_of_stock' => 'Rupture de stock',
                    ]),

                SelectFilter::make('size')
                    ->label('Taille')
                    ->options([
                        'XS' => 'XS',
                        'S' => 'S',
                        'M' => 'M',
                        'L' => 'L',
                        'XL' => 'XL',
                        '2XL' => '2XL',
                        '3XL' => '3XL',
                        '4XL' => '4XL',
                        '5XL' => '5XL',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
