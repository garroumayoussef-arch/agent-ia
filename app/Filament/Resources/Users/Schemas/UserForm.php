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

                // Étape T19 (écriture, manager) / T21 (lecture, viewer) —
                // permissions par entrepôt : n'a d'effet réel que pour un
                // utilisateur ayant le rôle manager OU viewer (cf.
                // ScopesToOwnWarehouses). Un admin garde un accès global
                // quel que soit le contenu de ce champ ; pour un compte
                // sans rôle, il reste inutilisé (D3 T21 : jamais étendu
                // aux comptes sans rôle).
                Select::make('warehouses')
                    ->label('Entrepôt(s) attribué(s)')
                    ->relationship('warehouses', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText('Pour un manager : restreint la création de mouvements, réceptions, expéditions et transferts à ces entrepôts (sans entrepôt attribué, aucune de ces opérations n\'est possible). Pour un viewer : restreint la CONSULTATION des stocks/mouvements par entrepôt à ces mêmes entrepôts (sans entrepôt attribué, aucune donnée de stock/mouvement n\'est visible). Sans effet pour un admin (accès global) ou un compte sans rôle.'),
            ]);
    }
}
