<?php

namespace App\Filament\Resources\Drivers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DriverForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->required(),

                TextInput::make('license_number')
                    ->label('Numéro de permis'),

                TextInput::make('phone')
                    ->label('Téléphone')
                    ->tel(),

                Select::make('user_id')
                    ->label('Compte utilisateur associé')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Optionnel : un chauffeur n\'a pas besoin d\'un compte pour accéder à l\'ERP.'),

                Toggle::make('is_active')
                    ->label('Actif')
                    ->default(true)
                    ->required(),
            ]);
    }
}
