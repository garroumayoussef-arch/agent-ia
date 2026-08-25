<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ScopesToOwnDriver;
use App\Filament\Concerns\ScopesToOwnWarehouses;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Étape T18 — reporting / inventaire consolidé par entrepôt : une ligne
 * par entrepôt actif, avec la quantité totale en stock et sa
 * valorisation (stock × prix d'achat), tous produits/variantes
 * confondus.
 *
 * Widget SÉPARÉ, en complément de StockOverview (stats globales,
 * inchangé) et de LowStockAlertByWarehouse (alertes T15, inchangé) :
 * ni l'un ni l'autre ne donne de vue consolidée par entrepôt, ce que ce
 * widget comble sans les remplacer.
 *
 * Périmètre strictement en lecture : aucun modèle, aucune migration,
 * aucune Resource T10-T17 et aucun système d'autorisation ne sont
 * modifiés — même règle de visibilité que LowStockAlertByWarehouse
 * (masqué uniquement pour un compte chauffeur).
 *
 * Étape T20 — n'étant pas une Resource, ce widget ne bénéficie pas de
 * getEloquentQuery() : sa requête est scopée directement ici, même
 * principe (managers uniquement, D1) que WarehouseStockResource/
 * StockMovementResource. Un entrepôt hors périmètre n'apparaît même
 * plus comme ligne du rapport (ni quantité ni valorisation à 0), il
 * est simplement absent.
 */
class WarehouseStockOverview extends TableWidget
{
    use ScopesToOwnDriver;
    use ScopesToOwnWarehouses;

    // Juste après les deux widgets d'alerte de stock bas (-10 et -9) :
    // le reporting consolidé complète l'information, il ne doit pas
    // passer avant les alertes.
    protected static ?int $sort = -8;

    protected int|string|array $columnSpan = 'full';

    /**
     * Même règle que LowStockAlertByWarehouse (T15) : masqué uniquement
     * pour un compte chauffeur, aucun nouveau système d'autorisation.
     */
    public static function canView(): bool
    {
        return static::isAdminOrManager() || static::currentDriver() === null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('📦 Reporting stock consolidé par entrepôt')
            ->description(
                'Quantité totale et valorisation (stock × prix d\'achat) par entrepôt actif, '
                .'tous produits et variantes confondus.'
            )
            ->query(function () {
                $allowedWarehouseIds = static::currentUserWarehouseIds();

                return Warehouse::query()
                    ->where('is_active', true)
                    ->when(
                        $allowedWarehouseIds !== null,
                        fn ($query) => $query->whereIn('id', $allowedWarehouseIds),
                    )
                    ->orderBy('name');
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Entrepôt')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_stock')
                    ->label('Quantité totale')
                    ->state(fn (Warehouse $record): int => (int) $record->warehouseStocks()->sum('stock'))
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('total_value')
                    ->label('Valorisation (prix d\'achat)')
                    ->state(fn (Warehouse $record): string => static::formatValuation(static::valuation($record)))
                    ->badge()
                    ->color('success'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Aucun entrepôt actif')
            ->emptyStateDescription('Activez au moins un entrepôt pour voir apparaître le reporting consolidé.')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }

    /**
     * Valorisation totale d'un entrepôt : somme, pour chaque ligne
     * warehouse_stocks, de stock × prix d'achat — celui de la VARIANTE
     * quand product_variant_id est renseigné, sinon celui du PRODUIT
     * parent (décision validée T18). Calculée en PHP plutôt qu'en SQL
     * agrégé : products.prix_achat et product_variants.prix_achat
     * peuvent diverger pour une même ligne, un agrégat SQL nécessiterait
     * un JOIN/COALESCE conditionnel bien plus fragile pour un gain de
     * performance non justifié au volume actuel du catalogue.
     */
    private static function valuation(Warehouse $warehouse): float
    {
        return (float) $warehouse->warehouseStocks()
            ->with(['product', 'productVariant'])
            ->get()
            ->sum(function (WarehouseStock $line): float {
                $unitPrice = $line->productVariant?->prix_achat ?? $line->product?->prix_achat ?? 0;

                return $line->stock * (float) $unitPrice;
            });
    }

    private static function formatValuation(float $value): string
    {
        return number_format($value, 2, ',', ' ').' €';
    }
}
