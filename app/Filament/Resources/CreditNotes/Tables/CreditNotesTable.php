<?php

namespace App\Filament\Resources\CreditNotes\Tables;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Étape T24 — table strictement en lecture, comme InvoicesTable (T23) :
 * aucune colonne d'action de mutation.
 */
class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label("N° avoir")
                    ->searchable()
                    ->sortable(),

                TextColumn::make('invoice.number')
                    ->label('Facture créditée')
                    ->searchable(),

                TextColumn::make('customer_name')
                    ->label('Client')
                    ->searchable(),

                TextColumn::make('scope')
                    ->label('Portée')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'total' ? 'Total' : 'Partiel')
                    ->color(fn (string $state): string => $state === 'total' ? 'danger' : 'warning'),

                TextColumn::make('settlement_type')
                    ->label('Règlement')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'refund' ? 'Remboursement' : 'Imputation future')
                    ->color('gray'),

                TextColumn::make('issued_at')
                    ->label('Émis le')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('total_ttc')
                    ->label('Total TTC crédité')
                    ->money('EUR')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->label('Portée')
                    ->options(['total' => 'Total', 'partial' => 'Partiel']),
                SelectFilter::make('settlement_type')
                    ->label('Règlement')
                    ->options(['refund' => 'Remboursement', 'future_invoice' => 'Imputation future']),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record): string => route('credit-notes.pdf', $record))
                    ->openUrlInNewTab(),
            ]);
    }
}
