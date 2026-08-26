<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Invoice;
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

                // Étape T31 — toujours recalculé depuis
                // Invoice::paymentStatus(), jamais un champ stocké (cf.
                // sa documentation).
                TextColumn::make('payment_status')
                    ->label('Paiement')
                    ->state(fn (Invoice $record): string => $record->paymentStatus())
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::paymentStatusLabel($state))
                    ->color(fn (string $state): string => static::paymentStatusColor($state)),
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

    public static function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            Invoice::PAYMENT_STATUS_UNPAID => 'Non payée',
            Invoice::PAYMENT_STATUS_PARTIAL => 'Partiellement payée',
            Invoice::PAYMENT_STATUS_PAID => 'Payée',
            default => $status,
        };
    }

    public static function paymentStatusColor(string $status): string
    {
        return match ($status) {
            Invoice::PAYMENT_STATUS_UNPAID => 'danger',
            Invoice::PAYMENT_STATUS_PARTIAL => 'warning',
            Invoice::PAYMENT_STATUS_PAID => 'success',
            default => 'gray',
        };
    }
}
