<?php

namespace App\Filament\Resources\SalesOrders\Schemas;

use App\Filament\Resources\SalesOrders\Tables\SalesOrdersTable;
use App\Models\SalesOrderItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SalesOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Commande')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Référence'),

                        TextEntry::make('status')
                            ->label('Statut')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => SalesOrdersTable::statusLabel($state))
                            ->color(fn (string $state): string => SalesOrdersTable::statusColor($state)),

                        TextEntry::make('customer.name')
                            ->label('Client')
                            ->placeholder('-'),

                        TextEntry::make('order_date')
                            ->label('Date de commande')
                            ->date('d/m/Y')
                            ->placeholder('-'),

                        TextEntry::make('user.name')
                            ->label('Créé par')
                            ->placeholder('Système / import'),

                        TextEntry::make('created_at')
                            ->label('Créé le')
                            ->dateTime('d/m/Y H:i'),
                    ]),

                Section::make('Lignes commandées')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('product.nom')
                                    ->label('Produit'),

                                TextEntry::make('productVariant')
                                    ->label('Variante')
                                    ->state(function (SalesOrderItem $record): string {
                                        $variant = $record->productVariant;

                                        if (! $variant) {
                                            return '-';
                                        }

                                        return implode(' / ', array_filter([
                                            $variant->size,
                                            $variant->color,
                                        ])) ?: ($variant->sku ?? '-');
                                    }),

                                TextEntry::make('quantity_ordered')
                                    ->label('Commandé'),

                                TextEntry::make('quantity_shipped')
                                    ->label('Expédié')
                                    ->badge()
                                    ->color(fn (SalesOrderItem $record): string => match (true) {
                                        $record->quantity_shipped >= $record->quantity_ordered => 'success',
                                        $record->quantity_shipped > 0 => 'warning',
                                        default => 'gray',
                                    }),

                                TextEntry::make('unit_price')
                                    ->label('Prix unitaire')
                                    ->money('EUR')
                                    ->placeholder('-'),
                            ])
                            ->columns(5),
                    ]),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('-'),
                    ])
                    ->visible(fn ($record): bool => filled($record?->notes)),
            ]);
    }
}
