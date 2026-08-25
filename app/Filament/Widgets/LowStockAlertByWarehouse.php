<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ScopesToOwnDriver;
use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\WarehouseStock;
use Filament\Actions\EditAction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Étape T15 — alerte de stock bas PAR ENTREPÔT, en complément de
 * LowStockAlert (stock global, inchangé, jamais touché par ce widget).
 *
 * Volontairement un widget SÉPARÉ plutôt qu'une fusion dans
 * LowStockAlert : un produit affecté directement (jamais passé par un
 * mouvement, donc sans ligne warehouse_stocks) resterait invisible
 * d'une vue basée uniquement sur warehouse_stocks — LowStockAlert
 * (basé sur Product.stock directement) n'a pas cet angle mort et doit
 * le conserver. Les deux widgets se complètent, aucun ne remplace
 * l'autre.
 *
 * Même seuil que le reste du projet (Product::LOW_STOCK_THRESHOLD),
 * réutilisé tel quel — pas de seuil configurable par entrepôt (hors
 * périmètre T15).
 *
 * Étape T20 — n'étant pas une Resource, ce widget ne bénéficie pas de
 * getEloquentQuery() : sa requête est scopée directement ici, même
 * principe (managers uniquement, D1) que WarehouseStockResource/
 * StockMovementResource.
 */
class LowStockAlertByWarehouse extends TableWidget
{
    use ScopesToOwnDriver;
    use ScopesToOwnWarehouses;

    // Juste après LowStockAlert (-10) : les deux alertes de stock bas
    // doivent rester regroupées en tête du dashboard.
    protected static ?int $sort = -9;

    protected int|string|array $columnSpan = 'full';

    /**
     * Même règle que LowStockAlert (T3-T9/durcissement) : masqué
     * uniquement pour un compte chauffeur, réutilisation directe de
     * ScopesToOwnDriver — BlocksChauffeurReadAccess reste incompatible
     * avec la signature Widget::canView(): bool, même limitation déjà
     * documentée dans LowStockAlert.
     */
    public static function canView(): bool
    {
        return static::isAdminOrManager() || static::currentDriver() === null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('⚠️ Alertes stock bas par entrepôt')
            ->description(
                'Lignes warehouse_stocks en stock bas ou en rupture (≤ '.Product::LOW_STOCK_THRESHOLD.' unités) '
                .'dans un entrepôt actif, même si le stock global du produit reste suffisant ailleurs.'
            )
            ->query(function () {
                $allowedWarehouseIds = static::currentUserWarehouseIds();

                return WarehouseStock::query()
                    ->where('stock', '<=', Product::LOW_STOCK_THRESHOLD)
                    ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
                    ->when(
                        $allowedWarehouseIds !== null,
                        fn ($query) => $query->whereIn('warehouse_id', $allowedWarehouseIds),
                    )
                    ->orderBy('stock');
            })
            ->columns([
                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label('Entrepôt')
                    ->searchable(),

                Tables\Columns\TextColumn::make('product.nom')
                    ->label('Produit')
                    ->searchable(),

                Tables\Columns\TextColumn::make('productVariant.sku')
                    ->label('Variante')
                    ->placeholder('-')
                    ->formatStateUsing(function ($state, WarehouseStock $record): string {
                        if (! $record->productVariant) {
                            return '-';
                        }

                        $parts = array_filter([
                            $record->productVariant->size,
                            $record->productVariant->color,
                        ]);

                        $label = implode(' / ', $parts);

                        if ($record->productVariant->sku) {
                            $label .= $label !== '' ? " — SKU : {$record->productVariant->sku}" : "SKU : {$record->productVariant->sku}";
                        }

                        return $label !== '' ? $label : '-';
                    }),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : 'warning')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->url(fn (WarehouseStock $record): ?string => $record->product
                        ? ProductResource::getUrl('edit', ['record' => $record->product])
                        : null)
                    ->visible(fn (WarehouseStock $record): bool => $record->product !== null),
            ])
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Aucune alerte de stock par entrepôt')
            ->emptyStateDescription('Tous les entrepôts actifs ont un stock au-dessus du seuil.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
