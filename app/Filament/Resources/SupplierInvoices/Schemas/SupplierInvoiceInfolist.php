<?php

namespace App\Filament\Resources\SupplierInvoices\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Facture fournisseur')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('supplier_invoice_number')
                            ->label('Numéro de facture (fournisseur)'),

                        TextEntry::make('invoice_date')
                            ->label('Date de la facture')
                            ->date('d/m/Y'),

                        TextEntry::make('supplier.name')
                            ->label('Fournisseur')
                            ->placeholder('-'),

                        TextEntry::make('purchaseOrder.reference')
                            ->label('Bon de commande')
                            ->placeholder('-'),

                        TextEntry::make('total_ht')
                            ->label('Total HT')
                            ->money('EUR'),

                        TextEntry::make('tax_amount')
                            ->label('Montant TVA')
                            ->money('EUR'),

                        TextEntry::make('total_ttc')
                            ->label('Total TTC')
                            ->money('EUR'),

                        TextEntry::make('user.name')
                            ->label('Enregistrée par')
                            ->placeholder('Système / import'),

                        TextEntry::make('created_at')
                            ->label('Enregistrée le')
                            ->dateTime('d/m/Y H:i'),
                    ]),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('-'),
                    ])
                    ->visible(fn ($record): bool => filled($record?->notes)),
            ]);
    }
}
