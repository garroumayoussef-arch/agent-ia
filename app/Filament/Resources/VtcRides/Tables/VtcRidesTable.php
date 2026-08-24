<?php

namespace App\Filament\Resources\VtcRides\Tables;

use App\Models\VtcRide;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VtcRidesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state))
                    ->color(fn (string $state): string => static::statusColor($state)),

                TextColumn::make('performed_at')
                    ->label('Date de prestation')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('Client')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('driver.name')
                    ->label('Chauffeur')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('vehicle.plate_number')
                    ->label('Véhicule')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('total_ht')
                    ->label('Montant HT')
                    // ->state() (pas ->formatStateUsing()) : Filament
                    // n'invoque formatStateUsing() que si l'état brut
                    // n'est pas déjà NULL, ce qui court-circuiterait
                    // silencieusement notre "-" explicite pour une
                    // course sans prix HT connu. ->state() calcule
                    // l'affichage à partir du $record, sans ce
                    // raccourci.
                    ->state(fn (VtcRide $record): string => static::formatMoneyOrDash($record->total_ht)),

                TextColumn::make('tax_status')
                    ->label('Statut fiscal')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::taxStatusLabel($state))
                    ->color(fn (string $state): string => static::taxStatusColor($state)),

                TextColumn::make('tax_amount')
                    ->label('TVA')
                    ->state(fn (VtcRide $record): string => static::formatTaxAmount($record)),

                TextColumn::make('total_ttc')
                    ->label('Total TTC')
                    ->state(fn (VtcRide $record): string => static::formatMoneyOrDash($record->total_ttc)),

                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Modifié le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        VtcRide::STATUS_DRAFT => static::statusLabel(VtcRide::STATUS_DRAFT),
                        VtcRide::STATUS_CONFIRMED => static::statusLabel(VtcRide::STATUS_CONFIRMED),
                        VtcRide::STATUS_CANCELLED => static::statusLabel(VtcRide::STATUS_CANCELLED),
                    ]),

                SelectFilter::make('tax_status')
                    ->label('Statut fiscal')
                    ->options([
                        VtcRide::TAX_STATUS_TAXABLE => static::taxStatusLabel(VtcRide::TAX_STATUS_TAXABLE),
                        VtcRide::TAX_STATUS_EXEMPT => static::taxStatusLabel(VtcRide::TAX_STATUS_EXEMPT),
                        VtcRide::TAX_STATUS_UNRESOLVED => static::taxStatusLabel(VtcRide::TAX_STATUS_UNRESOLVED),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    // Le modèle refuse la suppression d'une course qui
                    // n'est plus en brouillon (cf. VtcRide::deleting) :
                    // on affiche une notification plutôt qu'une erreur
                    // brute.
                    ->action(function (VtcRide $record) {
                        try {
                            $record->delete();

                            Notification::make()
                                ->title('Course supprimée')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Suppression impossible')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            VtcRide::STATUS_DRAFT => 'Brouillon',
            VtcRide::STATUS_CONFIRMED => 'Confirmée',
            VtcRide::STATUS_CANCELLED => 'Annulée',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            VtcRide::STATUS_DRAFT => 'gray',
            VtcRide::STATUS_CONFIRMED => 'success',
            VtcRide::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }

    public static function taxStatusLabel(string $taxStatus): string
    {
        return match ($taxStatus) {
            VtcRide::TAX_STATUS_TAXABLE => 'Taxable',
            VtcRide::TAX_STATUS_EXEMPT => 'Exonérée / non facturée',
            VtcRide::TAX_STATUS_UNRESOLVED => 'Non résolue',
            default => $taxStatus,
        };
    }

    public static function taxStatusColor(string $taxStatus): string
    {
        return match ($taxStatus) {
            VtcRide::TAX_STATUS_TAXABLE => 'success',
            VtcRide::TAX_STATUS_EXEMPT => 'info',
            VtcRide::TAX_STATUS_UNRESOLVED => 'warning',
            default => 'gray',
        };
    }

    /**
     * Formate un montant nullable en EUR — jamais "0,00 €" pour une
     * valeur NULL (inconnue), toujours un tiret explicite à la place.
     */
    public static function formatMoneyOrDash(?string $amount): string
    {
        return $amount !== null
            ? number_format((float) $amount, 2, ',', ' ').' €'
            : '-';
    }

    /**
     * TVA : distingue explicitement les 3 états (cf. VtcRide::$tax_status)
     * plutôt que de laisser un formateur monétaire générique confondre
     * NULL (inconnue) et 0 (connue, nulle).
     */
    public static function formatTaxAmount(VtcRide $record): string
    {
        return match ($record->tax_status) {
            VtcRide::TAX_STATUS_UNRESOLVED => 'TVA non résolue',
            VtcRide::TAX_STATUS_EXEMPT => '0,00 € (exonérée)',
            VtcRide::TAX_STATUS_TAXABLE => number_format((float) $record->tax_amount, 2, ',', ' ').' €',
            default => '-',
        };
    }
}
