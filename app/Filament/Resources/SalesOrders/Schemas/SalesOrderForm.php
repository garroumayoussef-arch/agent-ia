<?php

namespace App\Filament\Resources\SalesOrders\Schemas;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class SalesOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Select::make('customer_id')
                    ->label('Client')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),

                TextInput::make('reference')
                    ->label('Référence')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->default(fn (): string => 'CMD-'.now()->format('Ymd').'-'.strtoupper(Str::random(4))),

                DatePicker::make('order_date')
                    ->label('Date de commande'),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),

                Repeater::make('items')
                    ->label('Lignes commandées')
                    ->relationship('items')
                    ->schema([

                        Select::make('product_id')
                            ->label('Produit')
                            ->options(fn () => Product::query()->orderBy('nom')->pluck('nom', 'id')->toArray())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('product_variant_id', null))
                            ->required(),

                        Select::make('product_variant_id')
                            ->label('Variante')
                            ->options(function (Get $get) {
                                $productId = $get('product_id');

                                if (! $productId) {
                                    return [];
                                }

                                return ProductVariant::query()
                                    ->where('product_id', $productId)
                                    ->orderBy('size')
                                    ->orderBy('color')
                                    ->get()
                                    ->mapWithKeys(fn (ProductVariant $variant) => [
                                        $variant->id => implode(' / ', array_filter([
                                            $variant->size ? 'Taille : '.$variant->size : null,
                                            $variant->color ? 'Couleur : '.$variant->color : null,
                                            $variant->sku ? 'SKU : '.$variant->sku : null,
                                        ])) ?: $variant->sku,
                                    ])
                                    ->toArray();
                            })
                            ->searchable()
                            // Même règle que StockMovementForm/PurchaseOrderForm :
                            // requise uniquement si le produit sélectionné a
                            // réellement des variantes (cf. garde-fou
                            // SalesOrderItem::creating()).
                            ->disabled(fn (Get $get): bool => ! $get('product_id')
                                || ! static::productHasVariants($get('product_id')))
                            ->required(fn (Get $get): bool => static::productHasVariants($get('product_id')))
                            ->helperText(function (Get $get): string {
                                if (! $get('product_id')) {
                                    return 'Sélectionnez d’abord un produit.';
                                }

                                return static::productHasVariants($get('product_id'))
                                    ? 'Ce produit a des variantes : sélectionnez celle concernée.'
                                    : "Ce produit n'a pas de variantes.";
                            }),

                        TextInput::make('quantity_ordered')
                            ->label('Quantité commandée')
                            ->numeric()
                            ->minValue(1)
                            ->required(),

                        TextInput::make('unit_price')
                            ->label('Prix unitaire')
                            ->numeric()
                            ->prefix('€'),

                    ])
                    ->columns(4)
                    ->defaultItems(1)
                    ->addActionLabel('Ajouter une ligne')
                    ->reorderable(false)
                    // Une fois la commande sortie du brouillon, les lignes
                    // sont figées (cf. SalesOrderItem::updating) :
                    // désactivé côté UI pour éviter une tentative
                    // d'édition vouée à échouer côté modèle.
                    ->disabled(fn (?SalesOrder $record): bool => $record !== null
                        && $record->status !== SalesOrder::STATUS_DRAFT)
                    ->columnSpanFull(),

            ]);
    }

    private static function productHasVariants(?int $productId): bool
    {
        if (! $productId) {
            return false;
        }

        return ProductVariant::query()->where('product_id', $productId)->exists();
    }
}
