<?php

namespace App\Filament\Resources\VtcRides\Schemas;

use App\Filament\Resources\VtcRides\Tables\VtcRidesTable;
use App\Models\VtcRide;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * N'affiche QUE les valeurs déjà calculées et stockées sur VtcRide —
 * aucun recalcul ici. Pour une course confirmée, ce sont donc les
 * valeurs historiques réellement figées qui s'affichent, qu'un
 * TaxRate ou un FiscalSetting ait changé depuis ou non.
 */
class VtcRideInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Course VTC')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Référence'),

                        TextEntry::make('status')
                            ->label('Statut')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => VtcRidesTable::statusLabel($state))
                            ->color(fn (string $state): string => VtcRidesTable::statusColor($state)),

                        TextEntry::make('performed_at')
                            ->label('Date de prestation')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('-'),

                        TextEntry::make('platform')
                            ->label('Plateforme')
                            ->placeholder('-'),

                        TextEntry::make('customer.name')
                            ->label('Client')
                            ->placeholder('-'),

                        TextEntry::make('driver.name')
                            ->label('Chauffeur')
                            ->placeholder('-'),

                        TextEntry::make('vehicle.plate_number')
                            ->label('Véhicule')
                            ->placeholder('-'),

                        TextEntry::make('user.name')
                            ->label('Créé par')
                            ->placeholder('Système / import'),
                    ]),

                Section::make('Facturation')
                    ->columns(2)
                    ->schema([
                        // ->state() (pas ->formatStateUsing()) partout
                        // ci-dessous où la colonne peut être NULL :
                        // Filament n'invoque formatStateUsing() que si
                        // l'état brut n'est pas déjà NULL, ce qui
                        // court-circuiterait silencieusement notre "-"
                        // explicite. ->state() calcule l'affichage à
                        // partir du $record, sans ce raccourci — vérifié
                        // en pratique : la valeur "TVA non résolue"
                        // n'apparaissait jamais tant que ce n'était pas
                        // corrigé.
                        TextEntry::make('price_ht')
                            ->label('Prix HT saisi')
                            ->state(fn (VtcRide $record): string => VtcRidesTable::formatMoneyOrDash($record->price_ht)),

                        TextEntry::make('discount_amount')
                            ->label('Remise')
                            ->state(fn (VtcRide $record): string => VtcRidesTable::formatMoneyOrDash($record->discount_amount)),

                        TextEntry::make('total_ht')
                            ->label('Montant HT (après remise)')
                            ->state(fn (VtcRide $record): string => VtcRidesTable::formatMoneyOrDash($record->total_ht)),

                        TextEntry::make('taxRate.label')
                            ->label('Taux de TVA (référence)')
                            ->placeholder('Aucun taux résolu'),

                        TextEntry::make('tax_rate')
                            ->label('Taux appliqué')
                            ->state(fn (VtcRide $record): string => $record->tax_rate !== null ? "{$record->tax_rate} %" : '-'),

                        TextEntry::make('tax_status')
                            ->label('Statut fiscal')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => VtcRidesTable::taxStatusLabel($state))
                            ->color(fn (string $state): string => VtcRidesTable::taxStatusColor($state)),

                        TextEntry::make('tax_amount')
                            ->label('Montant de la TVA')
                            ->state(fn (VtcRide $record): string => VtcRidesTable::formatTaxAmount($record)),

                        TextEntry::make('total_ttc')
                            ->label('Total TTC')
                            ->state(fn (VtcRide $record): string => VtcRidesTable::formatMoneyOrDash($record->total_ttc)),
                    ]),

                Section::make('Mention légale')
                    ->schema([
                        TextEntry::make('legal_mention')
                            ->label(''),
                    ])
                    ->visible(fn (?VtcRide $record): bool => filled($record?->legal_mention)),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('-'),
                    ])
                    ->visible(fn (?VtcRide $record): bool => filled($record?->notes)),
            ]);
    }
}
