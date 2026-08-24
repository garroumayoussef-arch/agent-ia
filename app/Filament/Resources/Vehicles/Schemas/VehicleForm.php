<?php

namespace App\Filament\Resources\Vehicles\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VehicleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('plate_number')
                    ->label('Immatriculation')
                    ->required()
                    ->unique(ignoreRecord: true),

                TextInput::make('brand')
                    ->label('Marque'),

                TextInput::make('model')
                    ->label('Modèle'),

                Toggle::make('is_active')
                    ->label('Actif')
                    ->default(true)
                    ->required(),
            ]);
    }
}
