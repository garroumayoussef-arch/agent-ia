<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Étape T10 : aucun champ de stock ici — un entrepôt est une pure
 * fiche d'identité à ce stade (nom, code, adresse). Le stock par
 * entrepôt (T11) n'existe pas encore.
 */
class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->required(),

                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->unique(ignoreRecord: true),

                TextInput::make('address')
                    ->label('Adresse'),

                Toggle::make('is_active')
                    ->label('Actif')
                    ->default(true)
                    ->required(),

                Toggle::make('is_default')
                    ->label('Entrepôt par défaut')
                    ->helperText(
                        'Un seul entrepôt par défaut à la fois : l\'activer ici désactive '
                        .'automatiquement ce statut sur tout autre entrepôt.'
                    ),

                Textarea::make('notes')
                    ->label('Notes')
                    ->columnSpanFull(),
            ]);
    }
}
