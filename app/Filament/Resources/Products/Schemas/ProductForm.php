<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                TextInput::make('reference')
                    ->label('Référence')
                    ->required()
                    ->unique(ignoreRecord: true),

                TextInput::make('nom')
                    ->label('Nom du produit')
                    ->required(),

                Select::make('brand_id')
                    ->label('Marque')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),

                Select::make('category_id')
                    ->label('Catégorie')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('type')
                    ->label('Type')
                    ->options([
                        'Player Version' => 'Player Version',
                        'Fan Version' => 'Fan Version',
                        'Kit Enfant' => 'Kit Enfant',
                        'Training' => 'Training',
                        'Veste' => 'Veste',
                        'Pantalon' => 'Pantalon',
                        'Short' => 'Short',
                    ])
                    ->required(),

                Select::make('club_id')
                    ->label('Club / Sélection')
                    ->relationship('club', 'name')
                    ->searchable()
                    ->preload(),

                TextInput::make('equipe')
                    ->label('Équipe'),

                TextInput::make('taille')
                    ->label('Taille'),

                Select::make('competition_id')
                    ->label('Compétition')
                    ->relationship('competition', 'name')
                    ->searchable()
                    ->preload(),

                Select::make('supplier_id')
                    ->label('Fournisseur')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload(),

                TextInput::make('stock')
                    ->label('Stock actuel')
                    ->numeric()
                    ->default(0)
                    ->disabled()
                    ->dehydrated(),

                TextInput::make('prix_achat')
                    ->label('Prix d’achat')
                    ->numeric()
                    ->prefix('€')
                    ->required(),

                TextInput::make('prix_vente')
                    ->label('Prix de vente')
                    ->numeric()
                    ->prefix('€')
                    ->required(),

                CheckboxList::make('marketplaces')
                    ->label('Canaux de vente')
                    ->columns(3)
                    ->options([
                        'amazon' => 'Amazon',
                        'ebay' => 'eBay',
                        'etsy' => 'Etsy',
                        'shopify' => 'Shopify',
                        'woocommerce' => 'WooCommerce',
                        'prestashop' => 'PrestaShop',
                        'facebook_marketplace' => 'Facebook Marketplace',
                        'facebook_shop' => 'Facebook Shop',
                        'instagram_shop' => 'Instagram Shop',
                        'tiktok_shop' => 'TikTok Shop',
                        'vinted' => 'Vinted',
                        'wallapop' => 'Wallapop',
                        'leboncoin' => 'Leboncoin',
                        'grailed' => 'Grailed',
                        'stockx' => 'StockX',
                        'goat' => 'GOAT',
                        'klekt' => 'Klekt',
                        'vestiaire' => 'Vestiaire Collective',
                        'whatnot' => 'Whatnot',
                        'rakuten' => 'Rakuten',
                        'cdiscount' => 'Cdiscount',
                        'fnac' => 'Fnac',
                        'bol' => 'Bol.com',
                        'jumia' => 'Jumia',
                        'avito' => 'Avito Maroc',
                    ])
                    ->columnSpanFull(),

                Repeater::make('variants')
                    ->label('Variantes du produit')
                    ->relationship('variants')
                    ->schema([

                        TextInput::make('sku')
                            ->label('SKU')
                            ->maxLength(255),

                        TextInput::make('barcode')
                            ->label('Code-barres')
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
                            ->required(),

                        TextInput::make('prix_achat')
                            ->label('Prix d’achat')
                            ->numeric()
                            ->prefix('€')
                            ->required(),

                        TextInput::make('prix_vente')
                            ->label('Prix de vente')
                            ->numeric()
                            ->prefix('€')
                            ->required(),

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

                    ])
                    ->columns(4)
                    ->defaultItems(0)
                    ->addActionLabel('Ajouter une variante')
                    ->collapsible()
                    ->cloneable()
                    ->columnSpanFull(),

                FileUpload::make('photos')
                    ->label('Photos')
                    ->multiple()
                    ->image()
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('Description')
                    ->columnSpanFull(),

            ]);
    }
}