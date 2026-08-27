<?php

namespace App\Filament\Resources\CreditNotes\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Étape T24 — restitue exclusivement les données FIGÉES sur
 * CreditNote/CreditNoteLine (jamais Invoice/Customer/CompanySettings
 * en direct) — même principe que InvoiceInfolist (T23).
 */
class CreditNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Avoir')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('number')->label("N° avoir"),
                        TextEntry::make('issued_at')->label("Date d'émission")->date('d/m/Y'),
                        TextEntry::make('scope')
                            ->label('Portée')
                            ->formatStateUsing(fn (string $state): string => $state === 'total' ? 'Total' : 'Partiel'),
                        TextEntry::make('invoice_number_reference')->label('Facture créditée'),
                        TextEntry::make('invoice_issued_at_reference')->label('Date de la facture')->date('d/m/Y'),
                        TextEntry::make('sales_order_reference')->label('Commande d\'origine'),
                        TextEntry::make('settlement_type')
                            ->label('Mode de règlement')
                            ->formatStateUsing(fn (string $state): string => $state === 'refund' ? 'Remboursement' : 'Imputation sur facture future'),
                        TextEntry::make('reason')->label('Motif')->columnSpanFull(),
                    ]),

                Section::make('Vendeur')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('seller_legal_name')->label('Raison sociale'),
                        TextEntry::make('seller_legal_form')->label('Forme juridique')->placeholder('-'),
                        TextEntry::make('seller_address')->label('Adresse'),
                        TextEntry::make('seller_postal_code')->label('Code postal'),
                        TextEntry::make('seller_city')->label('Ville'),
                        TextEntry::make('seller_country')->label('Pays'),
                        TextEntry::make('seller_siren')->label('SIREN'),
                        TextEntry::make('seller_vat_number')->label('N° TVA intracommunautaire')->placeholder('-'),
                    ]),

                Section::make('Client')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('customer_name')->label('Nom'),
                        TextEntry::make('customer_company')->label('Société')->placeholder('-'),
                        TextEntry::make('customer_type')
                            ->label('Type')
                            ->formatStateUsing(fn (string $state): string => $state === 'business' ? 'Professionnel' : 'Particulier'),
                        TextEntry::make('customer_siren')->label('SIREN')->placeholder('-'),
                        TextEntry::make('customer_address')->label('Adresse'),
                        TextEntry::make('customer_city')->label('Ville'),
                    ]),

                Section::make('Lignes créditées')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->columns(5)
                            ->schema([
                                TextEntry::make('product_name')->label('Produit'),
                                TextEntry::make('variant_description')->label('Variante')->placeholder('-'),
                                TextEntry::make('quantity')->label('Qté'),
                                TextEntry::make('unit_price_ht')->label('PU HT')->money('EUR'),
                                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR')->placeholder('-'),
                            ]),
                    ]),

                Section::make('Montants')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('total_ht')->label('Total HT crédité')->money('EUR'),
                        TextEntry::make('tax_amount')->label('Total TVA créditée')->money('EUR')->placeholder('-'),
                        TextEntry::make('total_ttc')->label('Total TTC crédité')->money('EUR')->placeholder('-'),
                    ]),

                // Chantier "retour physique" (Option 3b) — strictement en
                // lecture, aucune action de mutation ici (cf.
                // HasCreditNoteReturnAction sur ViewCreditNote). Passe par
                // CreditNoteLine::returns() (relation additive) : aucune
                // relation ajoutée sur CreditNote lui-même, qui reste
                // intégralement inchangé (T24, chantier D1-D7).
                Section::make('Retours physiques')
                    ->schema([
                        RepeatableEntry::make('lines')
                            ->label('')
                            ->schema([
                                TextEntry::make('product_name')
                                    ->label('Ligne')
                                    ->columnSpanFull(),

                                RepeatableEntry::make('returns')
                                    ->label('')
                                    ->columns(5)
                                    ->schema([
                                        TextEntry::make('returned_at')->label('Date')->date('d/m/Y'),
                                        TextEntry::make('quantity')->label('Qté retournée'),
                                        TextEntry::make('condition')
                                            ->label('État')
                                            ->badge()
                                            ->formatStateUsing(fn (string $state): string => $state === 'vendable' ? 'Vendable' : 'Défectueux')
                                            ->color(fn (string $state): string => $state === 'vendable' ? 'success' : 'danger'),
                                        TextEntry::make('user.name')->label('Enregistré par')->placeholder('-'),
                                        TextEntry::make('reference')->label('Référence')->placeholder('-'),
                                    ])
                                    ->placeholder('Aucun retour enregistré pour cette ligne.'),
                            ]),
                    ]),
            ]);
    }
}
