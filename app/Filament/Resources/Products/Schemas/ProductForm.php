<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\ProductVariant;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(fn (Schema $schema): array => [

                ...($schema->getOperation() === 'create' ? [
                    Select::make('activity')
                        ->label('Activité')
                        ->placeholder('Choisir une activité')
                        ->options([
                            'sport' => 'Sport',
                            'bebe' => 'Bébé',
                            'moto' => 'Moto',
                            'artisanat' => 'Artisanat',
                        ])
                        ->required()
                        ->rules(['string', Rule::in(['sport', 'bebe', 'moto', 'artisanat'])]),
                ] : []),

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
                    ->required()
                    ->rules(fn (Get $get): array => [
                        Rule::exists('categories', 'id')->where(fn (Builder $query) => $query->where(
                            fn (Builder $query) => $query->whereNull('activity')
                                ->orWhere('activity', is_string($get('activity')) ? $get('activity') : ''),
                        )),
                    ], condition: fn (string $operation): bool => $operation === 'create')
                    ->validationMessages([
                        'exists' => 'Choisissez une catégorie transverse ou compatible avec l’activité sélectionnée.',
                    ]),

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
                    ->label('Équipe')
                    ->default('N/A'),

                TextInput::make('taille')
                    ->label('Taille')
                    ->default('N/A'),

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
                    ->rules([fn (Repeater $component): Closure => self::variantIdentifiersRule($component)])
                    ->schema([

                        TextInput::make('sku')
                            ->label('SKU')
                            ->required()
                            ->unique(table: ProductVariant::class, column: 'sku', ignoreRecord: true)
                            ->maxLength(255),

                        TextInput::make('barcode')
                            ->label('Code-barres')
                            ->unique(table: ProductVariant::class, column: 'barcode', ignoreRecord: true)
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

    private static function variantIdentifiersRule(Repeater $component): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($component): void {
            if (! is_array($value)) {
                return;
            }

            foreach (['sku' => 'SKU', 'barcode' => 'code-barres'] as $field => $label) {
                $seen = [];
                $paths = [];

                foreach ($value as $key => $row) {
                    $identifier = $row[$field] ?? null;

                    if ($identifier === null || $identifier === '') {
                        continue;
                    }

                    $path = "{$attribute}.{$key}.{$field}";
                    // Ne pas utiliser les identifiants comme clés : PHP convertirait certaines chaînes en entiers.
                    $duplicate = array_search($identifier, $seen, true);

                    if ($duplicate !== false) {
                        $message = "Ce {$label} est déjà utilisé par une autre ligne de variantes.";
                        $fail($paths[$duplicate], $message);
                        $fail($path, $message);
                    } else {
                        $seen[] = $identifier;
                        $paths[] = $path;
                    }

                    // Laravel ignore unique pour les chaînes blanches. Les contrôler sans les transformer.
                    if ($field !== 'barcode' || ! is_string($identifier)
                        || preg_match('/\A[ \t\n\r\x00\x0B]+\z/', $identifier) !== 1) {
                        continue;
                    }

                    $record = ($component->getItems()[$key] ?? null)?->getRecord();
                    $query = ProductVariant::query()->where('barcode', $identifier);

                    if ($record instanceof ProductVariant && $record->exists) {
                        $query->where($record->getQualifiedKeyName(), '!=', $record->getOriginal($record->getKeyName()));
                    }

                    if ($query->exists()) {
                        $fail($path, 'Ce code-barres est déjà utilisé par une autre variante.');
                    }
                }
            }
        };
    }
}
