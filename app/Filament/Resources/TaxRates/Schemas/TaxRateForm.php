<?php

namespace App\Filament\Resources\TaxRates\Schemas;

use App\Models\TaxRate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Formulaire générique de configuration d'un taux/traitement de TVA —
 * aucune valeur (10 %, 20 %...) n'est codée en dur ici : l'admin saisit
 * lui-même le taux, ou choisit "exonéré" auquel cas aucun taux
 * numérique n'est demandé (cf. TaxRate::TYPE_EXEMPT — un taux à 0 % et
 * un régime "non applicable" restent deux choses structurellement
 * différentes, cf. la colonne `type`).
 */
class TaxRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->label('Libellé')
                    ->required()
                    ->helperText('Ex. "Taux normal — marchandises", "Transport de voyageurs (VTC)", "Franchise en base de TVA".'),

                Select::make('type')
                    ->label('Type')
                    ->options([
                        TaxRate::TYPE_PERCENTAGE => 'Taux (pourcentage)',
                        TaxRate::TYPE_EXEMPT => 'Exonéré / TVA non applicable',
                    ])
                    ->required()
                    ->live()
                    ->default(TaxRate::TYPE_PERCENTAGE),

                TextInput::make('rate')
                    ->label('Taux (%)')
                    ->numeric()
                    ->suffix('%')
                    ->visible(fn (Get $get): bool => $get('type') === TaxRate::TYPE_PERCENTAGE)
                    ->required(fn (Get $get): bool => $get('type') === TaxRate::TYPE_PERCENTAGE),

                Textarea::make('legal_mention')
                    ->label('Mention légale')
                    ->rows(2)
                    ->visible(fn (Get $get): bool => $get('type') === TaxRate::TYPE_EXEMPT)
                    ->required(fn (Get $get): bool => $get('type') === TaxRate::TYPE_EXEMPT)
                    ->helperText('Ex. "TVA non applicable, article 293 B du CGI".')
                    ->columnSpanFull(),

                Toggle::make('is_default_purchase')
                    ->label('Taux par défaut à l\'achat')
                    ->helperText('Utilisé quand un produit n\'a pas de taux d\'achat spécifique.'),

                Toggle::make('is_default_sale')
                    ->label('Taux par défaut à la vente')
                    ->helperText('Utilisé quand un produit n\'a pas de taux de vente spécifique.'),

                Toggle::make('is_active')
                    ->label('Actif')
                    ->default(true)
                    ->required(),
            ]);
    }
}
