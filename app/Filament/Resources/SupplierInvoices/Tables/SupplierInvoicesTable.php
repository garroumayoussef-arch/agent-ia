<?php

namespace App\Filament\Resources\SupplierInvoices\Tables;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Filament\Actions\ViewAction;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Étape T28 — aucune EditAction/DeleteAction enregistrée ici (facture
 * fournisseur immuable dès l'enregistrement, cf. SupplierInvoice::booted())
 * — même principe que InvoicesTable/CreditNotesTable (T23/T24) :
 * ViewAction seule, jamais de bouton menant à une opération que le
 * modèle refuserait de toute façon.
 */
class SupplierInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier_invoice_number')
                    ->label('Numéro de facture')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('purchaseOrder.reference')
                    ->label('Bon de commande')
                    ->searchable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('invoice_date')
                    ->label('Date de la facture')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_ttc')
                    ->label('Total TTC')
                    ->money('EUR')
                    ->sortable(),

                // Étape T30 — toujours recalculé depuis
                // SupplierInvoice::paymentStatus(), jamais un champ
                // stocké (cf. sa documentation).
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Paiement')
                    ->state(fn (SupplierInvoice $record): string => $record->paymentStatus())
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::paymentStatusLabel($state))
                    ->color(fn (string $state): string => static::paymentStatusColor($state)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Enregistrée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Fournisseur')
                    ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable(),

                // Pas de filtre sur le statut de paiement : c'est une
                // colonne CALCULÉE (jamais stockée en base, cf.
                // SupplierInvoice::paymentStatus()), non filtrable par
                // une simple clause WHERE — hors périmètre V1, non
                // demandé explicitement.
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            SupplierInvoice::PAYMENT_STATUS_UNPAID => 'Non payée',
            SupplierInvoice::PAYMENT_STATUS_PARTIAL => 'Partiellement payée',
            SupplierInvoice::PAYMENT_STATUS_PAID => 'Payée',
            default => $status,
        };
    }

    public static function paymentStatusColor(string $status): string
    {
        return match ($status) {
            SupplierInvoice::PAYMENT_STATUS_UNPAID => 'danger',
            SupplierInvoice::PAYMENT_STATUS_PARTIAL => 'warning',
            SupplierInvoice::PAYMENT_STATUS_PAID => 'success',
            default => 'gray',
        };
    }
}
