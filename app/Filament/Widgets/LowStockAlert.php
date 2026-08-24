<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\ScopesToOwnDriver;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LowStockAlert extends TableWidget
{
    use ScopesToOwnDriver;

    // Priorité d'affichage la plus haute du dashboard : une alerte de
    // stock bas doit être vue en premier, avant les statistiques.
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    /**
     * Durcissement final (post T3-T9) : ce widget interroge Product
     * directement (->query() ci-dessous), sans jamais passer par
     * ProductResource::canViewAny()/canView() — il contournait donc
     * intégralement la restriction chauffeur posée en T6
     * (BlocksChauffeurReadAccess), exposant les mêmes données
     * (référence, nom, catégorie, stock) par une autre porte.
     *
     * BlocksChauffeurReadAccess n'est PAS réutilisable ici par simple
     * composition : Resource::canView(Model $record) attend un
     * paramètre, alors que Widget::canView(): bool n'en attend aucun —
     * les deux signatures sont incompatibles (erreur de déclaration si
     * on essayait). On réutilise donc directement ScopesToOwnDriver
     * (isAdminOrManager()/currentDriver(), tous deux sans paramètre),
     * exactement comme le fait déjà VtcRideOverview::canView() pour un
     * widget — même mécanisme existant, aucune nouvelle architecture.
     *
     * Règle : masqué uniquement pour un chauffeur (Driver.user_id, pas
     * admin/manager) ; tout autre profil (y compris un utilisateur
     * sans rôle ni Driver) garde le comportement actuel, inchangé.
     * StockOverview n'est pas concerné par ce changement.
     */
    public static function canView(): bool
    {
        return static::isAdminOrManager() || static::currentDriver() === null;
    }

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
