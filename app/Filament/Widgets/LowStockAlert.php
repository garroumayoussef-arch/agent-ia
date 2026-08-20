<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LowStockAlert extends TableWidget
{
    // Priorité d'affichage la plus haute du dashboard : une alerte de
    // stock bas doit être vue en premier, avant les statistiques.
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('⚠️ Alertes stock bas')
            ->description(
                'Produits en stock bas ou en rupture (≤ '.Product::LOW_STOCK_THRESHOLD.' unités). '
                .'Product.stock reste toujours à jour, qu\'il vienne de variantes ou d\'un stock direct.'
            )
            ->query(
                Product::query()
                    ->where('stock', '<=', Product::LOW_STOCK_THRESHOLD)
                    ->orderBy('stock')
            )
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable(),

                Tables\Columns\TextColumn::make('nom')
                    ->label('Produit')
                    ->searchable(),

                Tables\Columns\TextColumn::make('categorie')
                    ->label('Catégorie')
                    ->badge(),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : 'warning')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Aucune alerte de stock')
            ->emptyStateDescription('Tous les produits ont un stock au-dessus du seuil.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
