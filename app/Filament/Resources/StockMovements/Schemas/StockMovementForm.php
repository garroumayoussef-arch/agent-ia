<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
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
                        return Product::all()
                            ->mapWithKeys(function ($product) {
                                return [$product->id => $product->nom];
                            })
                            ->toArray();
                    })
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('type')
                    ->label('Type de mouvement')
                    ->options([
                        'purchase'   => '🟢 Achat',
                        'sale'       => '🔴 Vente',
                        'return'     => '🟡 Retour',
                        'adjustment' => '🟠 Ajustement',
                        'transfer'   => '🔵 Transfert',
                        'inventory'  => '⚪ Inventaire',
                    ])
                    ->required(),

                TextInput::make('quantity')
                    ->label('Quantité')
                    ->numeric()
                    ->minValue(1)
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