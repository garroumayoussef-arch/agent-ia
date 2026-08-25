<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->required(),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),

                TextInput::make('password')
                    ->label('Mot de passe')
                    ->password()
                    ->revealable()
                    // Le cast 'hashed' déclaré sur User::$casts se charge
                    // du hachage automatiquement à la sauvegarde : pas
                    // besoin de Hash::make() ici.
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText('Laisser vide pour ne pas modifier le mot de passe existant.'),

                Select::make('roles')
                    ->label('Rôle(s)')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText('Sans rôle, l’utilisateur a un accès en lecture seule (lecteur) à tout le panel, mais ne peut rien créer/modifier/supprimer.'),

                // Étape T19 — permissions par entrepôt : n'a d'effet
                // réel que pour un utilisateur ayant le rôle manager
                // (cf. ScopesToOwnWarehouses, décision D3). Un admin
                // garde un accès global quel que soit le contenu de ce
                // champ ; pour viewer/sans rôle, il reste inutilisé.
                Select::make('warehouses')
                    ->label('Entrepôt(s) attribué(s)')
                    ->relationship('warehouses', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText('Pour un manager : restreint la création de mouvements, réceptions, expéditions et transferts à ces entrepôts. Sans entrepôt attribué, un manager ne peut effectuer aucune de ces opérations. Sans effet pour un admin (accès global) ou un viewer.'),
            ]);
    }
}
