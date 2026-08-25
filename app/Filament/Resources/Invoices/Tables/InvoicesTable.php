<?php

namespace App\Filament\Resources\Invoices\Tables;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Étape T23 — table strictement en lecture : aucune colonne d'action
 * de mutation (pas de recordActions d'édition/suppression) — cohérent
 * avec InvoiceResource, qui ne déclare aucune page create/edit.
 */
class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('N° facture')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Client')
                    ->searchable(),

                TextColumn::make('customer_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'business' ? 'Professionnel' : 'Particulier')
                    ->color(fn (string $state): string => $state === 'business' ? 'warning' : 'gray'),

                TextColumn::make('salesOrder.reference')
                    ->label('Commande')
                    ->searchable(),

                TextColumn::make('issued_at')
                    ->label('Émise le')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('total_ttc')
                    ->label('Total TTC')
                    ->money('EUR')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer_type')
                    ->label('Type de client')
                    ->options([
                        'individual' => 'Particulier',
                        'business' => 'Professionnel',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record): string => route('invoices.pdf', $record))
                    ->openUrlInNewTab(),
            ]);
    }
}
