<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class StockMovementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Produit')
                    ->options(function () {
                        return Product::query()
                            ->orderBy('nom')
                            ->pluck('nom', 'id')
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('product_variant_id', null);
                    })
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
                            ->mapWithKeys(function (ProductVariant $variant) {
                                $parts = [];

                                if ($variant->size) {
                                    $parts[] = 'Taille : ' . $variant->size;
                                }

                                if ($variant->color) {
                                    $parts[] = 'Couleur : ' . $variant->color;
                                }

                                if ($variant->version) {
                                    $parts[] = 'Version : ' . $variant->version;
                                }

                                $label = implode(' / ', $parts);

                                if ($variant->sku) {
                                    $label .= ' — SKU : ' . $variant->sku;
                                }

                                $label .= ' — Stock : ' . $variant->stock;

                                return [
                                    $variant->id => $label,
                                ];
                            })
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get): bool => ! $get('product_id'))
                    ->required()
                    ->helperText('Sélectionnez d’abord un produit.'),

                Select::make('type')
                    ->label('Type de mouvement')
                    ->options([
                        'purchase' => '🟢 Achat',
                        'sale' => '🔴 Vente',
                        'return' => '🟡 Retour',
                        'adjustment' => '🟠 Ajustement',
                        // 'transfer' retiré : le multi-entrepôts n'est pas encore implémenté,
                        // ce type est bloqué côté modèle (StockMovement) tant qu'il n'est pas prêt.
                        'inventory' => '⚪ Inventaire',
                    ])
                    ->live()
                    ->required(),

                TextInput::make('quantity')
                    ->label('Quantité')
                    ->numeric()
                    ->minValue(fn (Get $get): int => in_array($get('type'), ['adjustment', 'inventory'], true) ? 0 : 1)
                    ->helperText(fn (Get $get): string => in_array($get('type'), ['adjustment', 'inventory'], true)
                        ? 'Valeur absolue du stock après ce mouvement (0 autorisé).'
                        : 'Nombre d’unités concernées par ce mouvement.')
                    ->required(),

                TextInput::make('reference')
                    ->label('Référence')
                    ->maxLength(255),

                Textarea::make('notes')
                    ->label('Commentaire')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}