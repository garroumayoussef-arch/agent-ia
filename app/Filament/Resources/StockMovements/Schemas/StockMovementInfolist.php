<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Models\Product;
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

                        TextEntry::make('warehouse.name')
                            ->label('Entrepôt')
                            ->placeholder('-'),

                        TextEntry::make('productVariant')
                            ->label('Variante')
                            ->state(function (StockMovement $record): string {
                                $variant = $record->productVariant;

                                if (! $variant) {
                                    return 'Aucune (mouvement sur le produit global)';
                                }

                                $parts = array_filter([
                                    $variant->attributeMirrorValue('size') ? 'Taille : '.$variant->attributeMirrorValue('size') : null,
                                    $variant->attributeMirrorValue('color') ? 'Couleur : '.$variant->attributeMirrorValue('color') : null,
                                    $variant->attributeMirrorValue('version') ? 'Version : '.$variant->attributeMirrorValue('version') : null,
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
                                'transfer_out' => '📤 Transfert sortant',
                                'transfer_in' => '📥 Transfert entrant',
                                default => $state,
                            })
                            ->color(fn (string $state): string => match ($state) {
                                'purchase', 'return' => 'success',
                                'sale' => 'danger',
                                'adjustment' => 'warning',
                                'transfer_out', 'transfer_in' => 'info',
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
                                $state <= Product::LOW_STOCK_THRESHOLD => 'warning',
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
