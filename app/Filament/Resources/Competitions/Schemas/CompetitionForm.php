<?php

namespace App\Filament\Resources\Competitions\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CompetitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('season'),
                TextInput::make('logo'),
            ]);
    }
}
