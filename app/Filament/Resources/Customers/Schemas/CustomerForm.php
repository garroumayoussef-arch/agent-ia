<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->required(),
                TextInput::make('company')
                    ->label('Société'),

                // Étape T23 — nécessaire pour déterminer les mentions
                // légales applicables à une facture (cf.
                // Invoice::generateFromSalesOrder()) : un client
                // professionnel sans SIREN renseigné bloque la
                // génération de facture, jamais silencieusement.
                Select::make('customer_type')
                    ->label('Type de client')
                    ->options([
                        Customer::TYPE_INDIVIDUAL => 'Particulier',
                        Customer::TYPE_BUSINESS => 'Professionnel',
                    ])
                    ->default(Customer::TYPE_INDIVIDUAL)
                    ->live()
                    ->required(),

                TextInput::make('email')
                    ->label('Email')
                    ->email(),
                TextInput::make('phone')
                    ->label('Téléphone')
                    ->tel(),
                TextInput::make('address')
                    ->label('Adresse')
                    ->columnSpanFull(),
                TextInput::make('postal_code')
                    ->label('Code postal'),
                TextInput::make('city')
                    ->label('Ville'),
                TextInput::make('country')
                    ->label('Pays'),

                TextInput::make('siren')
                    ->label('SIREN')
                    ->visible(fn (Get $get): bool => $get('customer_type') === Customer::TYPE_BUSINESS)
                    ->helperText('Obligatoire pour facturer ce client (mention légale requise sur une facture B2B).'),
                TextInput::make('vat_number')
                    ->label('N° TVA intracommunautaire')
                    ->visible(fn (Get $get): bool => $get('customer_type') === Customer::TYPE_BUSINESS)
                    ->helperText('Requis uniquement pour une vente professionnelle intracommunautaire.'),

                Toggle::make('is_active')
                    ->label('Actif')
                    ->default(true)
                    ->required(),
                Textarea::make('notes')
                    ->label('Notes')
                    ->columnSpanFull(),
            ]);
    }
}
