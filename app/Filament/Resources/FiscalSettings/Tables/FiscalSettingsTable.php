<?php

namespace App\Filament\Resources\FiscalSettings\Tables;

use App\Models\FiscalSetting;
use App\Models\TaxRate;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FiscalSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('activity')
                    ->label('Activité'),

                TextColumn::make('label')
                    ->label('Libellé')
                    ->placeholder('-'),

                TextColumn::make('taxRate.label')
                    ->label('Taux configuré')
                    ->placeholder('Non configuré'),

                TextColumn::make('regime')
                    ->label('Régime actuel')
                    ->badge()
                    // ->state() (pas ->formatStateUsing()) : 'regime'
                    // n'est même pas une vraie colonne, et surtout
                    // taxRate peut être NULL — un raccourci sur état NULL
                    // aurait ici aussi masqué le libellé "Non configuré".
                    ->state(fn (FiscalSetting $record): string => static::describeRegime($record->taxRate))
                    ->color(fn (FiscalSetting $record): string => static::regimeColor($record->taxRate)),

                TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * Décrit le régime effectif : jamais "0 %" pour NULL (non
     * configuré) ni pour un taux exonéré (structurellement différent).
     */
    public static function describeRegime(?TaxRate $taxRate): string
    {
        if ($taxRate === null) {
            return 'Non configuré';
        }

        return $taxRate->isExempt()
            ? 'Exonérée / non facturée'
            : "Taxable ({$taxRate->rate} %)";
    }

    public static function regimeColor(?TaxRate $taxRate): string
    {
        if ($taxRate === null) {
            return 'warning';
        }

        return $taxRate->isExempt() ? 'info' : 'success';
    }
}
