<?php

namespace App\Filament\Resources\VtcRides\Schemas;

use App\Models\VtcRide;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Ne comporte volontairement AUCUN champ pour tax_rate_id/tax_rate/
 * gross_tax_amount/tax_amount/total_ht/total_ttc/tax_status/
 * legal_mention : ce sont des valeurs calculées par VtcRide lui-même
 * (cf. VtcRide::booted()), jamais saisies ni recalculées ici. Elles ne
 * sont donc jamais soumises par ce formulaire, ce qui rend structurellement
 * impossible de les modifier directement depuis l'interface — la garde
 * posée dans VtcRide::updating() reste la protection de dernier recours.
 *
 * driver_id/vehicle_id restent volontairement non requis ici : une
 * course peut être préparée en brouillon sans eux (cf.
 * VtcRide::markAsConfirmed(), seule à les exiger).
 */
class VtcRideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('reference')
                    ->label('Référence')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->default(fn (): string => 'VTC-'.now()->format('Ymd').'-'.strtoupper(Str::random(4))),

                Select::make('customer_id')
                    ->label('Client')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),

                Select::make('driver_id')
                    ->label('Chauffeur')
                    ->relationship('driver', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Obligatoire pour confirmer la course, pas pour l\'enregistrer en brouillon.'),

                Select::make('vehicle_id')
                    ->label('Véhicule')
                    ->relationship('vehicle', 'plate_number')
                    ->searchable()
                    ->preload()
                    ->helperText('Obligatoire pour confirmer la course, pas pour l\'enregistrer en brouillon.'),

                TextInput::make('platform')
                    ->label('Plateforme')
                    ->helperText('Optionnel : direct, Uber, Bolt...'),

                DateTimePicker::make('performed_at')
                    ->label('Date et heure de la prestation'),

                TextInput::make('price_ht')
                    ->label('Prix HT')
                    ->numeric()
                    ->prefix('€')
                    ->disabled(fn (?VtcRide $record): bool => $record !== null
                        && $record->status !== VtcRide::STATUS_DRAFT),

                TextInput::make('discount_amount')
                    ->label('Remise')
                    ->numeric()
                    ->prefix('€')
                    ->default(0)
                    ->disabled(fn (?VtcRide $record): bool => $record !== null
                        && $record->status !== VtcRide::STATUS_DRAFT),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
