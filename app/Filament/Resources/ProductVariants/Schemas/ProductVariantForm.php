<?php

namespace App\Filament\Resources\ProductVariants\Schemas;

use App\Models\ProductVariant;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Validation\ValidationRule;

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
                    ->rules(fn (TextInput $component): array => [self::blankBarcodeRule($component)])
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

    private static function blankBarcodeRule(TextInput $component): ValidationRule
    {
        $record = $component->getRecord();
        $ignoredId = $record instanceof ProductVariant && $record->exists
            ? $record->getRawOriginal($record->getKeyName())
            : null;

        return new class($ignoredId, $component->getMaxLength()) implements ValidationRule
        {
            // Laravel enveloppe cette ValidationRule dans une ImplicitRule : les blancs sont controles.
            public bool $implicit = true;

            public function __construct(private int|string|null $ignoredId, private int $maxLength) {}

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                // Meme ensemble de caracteres que trim() dans Validator, sans transformer la valeur.
                if (! is_string($value) || preg_match('/\A[ \t\n\r\x00\x0B]+\z/', $value) !== 1) {
                    return;
                }

                if (mb_strlen($value) > $this->maxLength) {
                    $fail('Le code-barres ne doit pas dépasser '.$this->maxLength.' caractères.');

                    return;
                }

                $query = ProductVariant::query()->where('barcode', $value);
                if ($this->ignoredId !== null) {
                    $query->whereKeyNot($this->ignoredId);
                }

                if ($query->exists()) {
                    $fail('Ce code-barres est déjà utilisé par une autre variante.');
                }
            }
        };
    }
}
