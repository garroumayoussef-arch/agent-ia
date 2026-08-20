<?php

namespace App\Filament\Resources\ProductVariants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductVariantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Select::make('product_id')
                    ->label('Produit')
                    ->relationship('product', 'nom')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                TextInput::make('barcode')
                    ->label('Code-barres')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Select::make('size')
                    ->label('Taille')
                    ->options([
                        'XS' => 'XS',
                        'S' => 'S',
                        'M' => 'M',
                        'L' => 'L',
                        'XL' => 'XL',
                        '2XL' => '2XL',
                        '3XL' => '3XL',
                        '4XL' => '4XL',
                        '5XL' => '5XL',
                    ])
                    ->searchable(),

                TextInput::make('color')
                    ->label('Couleur')
                    ->maxLength(255),

                Select::make('version')
                    ->label('Version')
                    ->options([
                        'Fan Version' => 'Fan Version',
                        'Player Version' => 'Player Version',
                        'Kids' => 'Kids',
                        'Training' => 'Training',
                        'Veste' => 'Veste',
                        'Pantalon' => 'Pantalon',
                        'Short' => 'Short',
                    ])
                    ->searchable(),

                TextInput::make('stock')
                    ->label('Stock')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->helperText('Le stock du produit parent est recalculé automatiquement à partir de la somme des stocks de toutes ses variantes.'),

                TextInput::make('prix_achat')
                    ->label('Prix d’achat')
                    ->numeric()
                    ->prefix('€'),

                TextInput::make('prix_vente')
                    ->label('Prix de vente')
                    ->numeric()
                    ->prefix('€'),

                TextInput::make('warehouse')
                    ->label('Entrepôt')
                    ->default('France'),

                Select::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'inactive' => 'Inactif',
                        'out_of_stock' => 'Rupture de stock',
                    ])
                    ->default('active')
                    ->required(),

            ]);
    }
}
