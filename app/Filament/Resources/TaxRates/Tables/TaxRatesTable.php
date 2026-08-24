<?php

namespace App\Filament\Resources\TaxRates\Tables;

use App\Models\TaxRate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TaxRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Libellé')
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::typeLabel($state))
                    ->color(fn (string $state): string => static::typeColor($state)),

                TextColumn::make('rate')
                    ->label('Taux')
                    // ->state() plutôt que ->formatStateUsing() : cette
                    // colonne peut être NULL (type exonéré), et
                    // formatStateUsing() n'est pas invoqué quand l'état
                    // brut est déjà NULL — jamais "0 %" pour un taux
                    // exonéré/non configuré, toujours un tiret explicite.
                    ->state(fn (TaxRate $record): string => $record->rate !== null ? "{$record->rate} %" : '-'),

                IconColumn::make('is_default_purchase')
                    ->label('Défaut achat')
                    ->boolean(),

                IconColumn::make('is_default_sale')
                    ->label('Défaut vente')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),

                TextColumn::make('legal_mention')
                    ->label('Mention légale')
                    ->placeholder('-')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        TaxRate::TYPE_PERCENTAGE => static::typeLabel(TaxRate::TYPE_PERCENTAGE),
                        TaxRate::TYPE_EXEMPT => static::typeLabel(TaxRate::TYPE_EXEMPT),
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

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            TaxRate::TYPE_PERCENTAGE => 'Taux',
            TaxRate::TYPE_EXEMPT => 'Exonéré',
            default => $type,
        };
    }

    public static function typeColor(string $type): string
    {
        return match ($type) {
            TaxRate::TYPE_PERCENTAGE => 'success',
            TaxRate::TYPE_EXEMPT => 'info',
            default => 'gray',
        };
    }
}
