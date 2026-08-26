<?php

namespace App\Filament\Resources\SupplierInvoices\Schemas;

use App\Filament\Resources\SupplierInvoices\Tables\SupplierInvoicesTable;
use App\Models\SupplierInvoice;
use Filament\Infolists\Components\RepeatableEntry;
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

                /*
                 * Étape T30 — statut et historique des paiements.
                 * Toujours recalculé depuis SupplierInvoice::paymentStatus()/
                 * amountPaid()/amountRemaining() : jamais un champ stocké,
                 * aucune désynchronisation possible avec les paiements
                 * réellement enregistrés.
                 */
                Section::make('Paiements')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('payment_status')
                            ->label('Statut')
                            ->state(fn (SupplierInvoice $record): string => $record->paymentStatus())
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => SupplierInvoicesTable::paymentStatusLabel($state))
                            ->color(fn (string $state): string => SupplierInvoicesTable::paymentStatusColor($state)),

                        TextEntry::make('amount_paid')
                            ->label('Montant réglé')
                            ->state(fn (SupplierInvoice $record): string => number_format($record->amountPaid(), 2, ',', ' ').' €'),

                        TextEntry::make('amount_remaining')
                            ->label('Solde restant dû')
                            ->state(fn (SupplierInvoice $record): string => number_format($record->amountRemaining(), 2, ',', ' ').' €'),

                        RepeatableEntry::make('payments')
                            ->label('Historique des paiements')
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('paid_at')
                                    ->label('Date')
                                    ->date('d/m/Y'),

                                TextEntry::make('amount')
                                    ->label('Montant')
                                    ->money('EUR'),

                                TextEntry::make('reference')
                                    ->label('Référence')
                                    ->placeholder('-'),

                                TextEntry::make('notes')
                                    ->label('Notes')
                                    ->placeholder('-'),

                                TextEntry::make('user.name')
                                    ->label('Enregistré par')
                                    ->placeholder('Système / import'),
                            ])
                            ->columns(5),
                    ]),
                    // Toujours visible, y compris sans aucun paiement
                    // encore enregistré : c'est précisément là que le
                    // statut "non payée" doit apparaître.
            ]);
    }
}
