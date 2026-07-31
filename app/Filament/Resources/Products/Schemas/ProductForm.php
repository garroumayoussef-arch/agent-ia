<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Forms\Components\CheckboxList;
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        
        return $schema
            ->components([
                TextInput::make('reference')
                    ->required()
                    ->unique(ignoreRecord: true),

                TextInput::make('nom')
                    ->required(),

                Select::make('categorie')
                    ->options([
                        'Club' => 'Club',
                        'Équipe nationale' => 'Équipe nationale',
                        'Chaussures' => 'Chaussures',
                        'Accessoires' => 'Accessoires',
                  ])
                  ->required(),
                Select::make('type')

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
                       ->label('Club')
    ->relationship('club', 'name')
    ->searchable()
    ->preload(),

                TextInput::make('equipe'),

                TextInput::make('taille'),

                TextInput::make('stock')
                    ->label('Stock actuel')
                    ->numeric()
                    ->default(0)
                    ->disabled()
                    ->dehydrated(),

                TextInput::make('prix_achat')
                    ->numeric()
                    ->required(),

                TextInput::make('prix_vente')
                    ->numeric()
                    ->required(),

                TextInput::make('fournisseur'),
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
                FileUpload::make('photos')
                    ->multiple()
                    ->image()
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->columnSpanFull(),
            ]);
    }
}