<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Étape T22 — évite le N+1 sur la nouvelle colonne "Entrepôts
            // attribués" (chaque ligne accède à $record->warehouses).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('warehouses'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                TextColumn::make('roles.name')
                    ->label('Rôle(s)')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => 'Administrateur',
                        'manager' => 'Gestionnaire',
                        'viewer' => 'Lecteur',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'manager' => 'warning',
                        'viewer' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('Lecteur (aucun rôle)'),

                // Étape T22 — inventaire : visible pour TOUS les
                // utilisateurs (D3), pas seulement les viewers, pour
                // repérer n'importe quel compte (manager y compris) sans
                // entrepôt attribué. Purement informatif : aucune action
                // d'attribution automatique, EditAction (déjà présent
                // ci-dessous) reste l'unique chemin de correction.
                TextColumn::make('warehouses_summary')
                    ->label('Entrepôts attribués')
                    ->state(fn (User $record): string => $record->warehouses->isEmpty()
                        ? 'Aucun'
                        : $record->warehouses->pluck('name')->join(', '))
                    ->badge()
                    ->color(fn (User $record): string => $record->warehouses->isEmpty() ? 'danger' : 'gray'),

                TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                // Étape T22 — inventaire des viewers sans entrepôt
                // attribué (utile après T21, D3 : leur accès en lecture
                // est fail-closed sans attribution). D2 — requête
                // strictement "role = viewer" : exclut un cumul de rôles
                // (ex. viewer + manager), qui n'est pas un "pur" viewer
                // au sens de cet inventaire.
                Filter::make('viewers_without_warehouse')
                    ->label('Viewers sans entrepôt')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereHas('roles', fn (Builder $rolesQuery) => $rolesQuery->where('name', 'viewer'))
                        ->whereDoesntHave('roles', fn (Builder $rolesQuery) => $rolesQuery->where('name', '!=', 'viewer'))
                        ->whereDoesntHave('warehouses'))
                    ->toggle(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
