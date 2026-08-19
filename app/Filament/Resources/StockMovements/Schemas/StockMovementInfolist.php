<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Models\StockMovement;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockMovementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Mouvement')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('product.nom')
                            ->label('Produit')
                            ->placeholder('-'),

                        TextEntry::make('productVariant')
                            ->label('Variante')
                            ->state(function (StockMovement $record): string {
                                $variant = $record->productVariant;

                                if (! $variant) {
                                    return 'Aucune (mouvement sur le produit global)';
                                }

                                $parts = array_filter([
                                    $variant->size ? 'Taille : '.$variant->size : null,
                                    $variant->color ? 'Couleur : '.$variant->color : null,
                                    $variant->version ? 'Version : '.$variant->version : null,
                                ]);

                                return $parts !== []
                                    ? implode(' / ', $parts)
                                    : ($variant->sku ?? '-');
                            }),

                        TextEntry::make('type')
                            ->label('Type de mouvement')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'purchase' => '🟢 Achat',
                                'sale' => '🔴 Vente',
                                'return' => '🟡 Retour',
                                'adjustment' => '🟠 Ajustement',
                                'inventory' => '⚪ Inventaire',
                                default => $state,
                            })
                            ->color(fn (string $state): string => match ($state) {
                                'purchase', 'return' => 'success',
                                'sale' => 'danger',
                                'adjustment' => 'warning',
                                default => 'gray',
                            }),

                        TextEntry::make('quantity')
                            ->label('Quantité'),

                        TextEntry::make('reference')
                            ->label('Référence')
                            ->placeholder('-'),
                    ]),

                Section::make('Stock')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('stock_before')
                            ->label('Stock avant'),

                        TextEntry::make('stock_after')
                            ->label('Stock après')
                            ->badge()
                            ->color(fn ($state): string => match (true) {
                                $state <= 0 => 'danger',
                                $state <= 5 => 'warning',
                                default => 'success',
                            }),
                    ]),

                Section::make('Traçabilité')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('Créé par')
                            ->placeholder('Système / import'),

                        TextEntry::make('created_at')
                            ->label('Créé le')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('-'),

                        TextEntry::make('updated_at')
                            ->label('Dernière modification')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('-'),
                    ]),

                TextEntry::make('notes')
                    ->label('Commentaire')
                    ->placeholder('-')
                    ->columnSpanFull(),
            ]);
    }
}
