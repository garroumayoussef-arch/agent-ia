<?php

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Étape T23 — restitue exclusivement les données déjà FIGÉES sur
 * Invoice/InvoiceLine (jamais un recalcul, jamais une relecture de
 * SalesOrder/Customer/CompanySettings) — même principe que
 * SalesOrderInfolist/VtcRideInfolist déjà dans ce projet.
 */
class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Facture')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('number')->label('N° facture'),
                        TextEntry::make('issued_at')->label('Date d\'émission')->date('d/m/Y'),
                        TextEntry::make('sale_completed_at')->label('Date de vente/prestation')->date('d/m/Y'),
                        TextEntry::make('salesOrder.reference')->label('Commande d\'origine')->placeholder('-'),
                        TextEntry::make('transaction_type')->label('Type d\'opération'),
                        TextEntry::make('operation_category')->label('Catégorie'),
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
                        TextEntry::make('seller_siret')->label('SIRET')->placeholder('-'),
                        TextEntry::make('seller_rcs_city')->label('RCS')->placeholder('-'),
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
                        TextEntry::make('customer_vat_number')->label('N° TVA')->placeholder('-'),
                        TextEntry::make('customer_address')->label('Adresse'),
                        TextEntry::make('customer_postal_code')->label('Code postal'),
                        TextEntry::make('customer_city')->label('Ville'),
                        TextEntry::make('customer_country')->label('Pays'),
                    ]),

                Section::make('Lignes')
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
                    ->columns(4)
                    ->schema([
                        TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                        TextEntry::make('discount_amount')->label('Remise')->money('EUR'),
                        TextEntry::make('tax_amount')->label('Total TVA')->money('EUR')->placeholder('-'),
                        TextEntry::make('total_ttc')->label('Total TTC')->money('EUR')->placeholder('-'),
                    ]),

                Section::make('Mentions')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('vat_exemption_mention_snapshot')->label('Mention TVA')->placeholder('-'),
                        TextEntry::make('payment_terms_snapshot')->label('Conditions de paiement')->placeholder('-'),
                        TextEntry::make('discount_terms_snapshot')->label('Conditions d\'escompte')->placeholder('-'),
                        TextEntry::make('late_penalty_snapshot')->label('Pénalités de retard')->placeholder('-'),
                        TextEntry::make('recovery_indemnity_amount_snapshot')->label('Indemnité forfaitaire de recouvrement')->money('EUR'),
                    ]),
            ]);
    }
}
