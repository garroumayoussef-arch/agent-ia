<?php

namespace App\Filament\Resources\Products;

use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    use HasRoleBasedAuthorization;

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'nom';

    /**
     * Pastille de compteur sur le menu "Produits" : nombre de produits
     * en stock bas ou en rupture (Product.stock reste toujours à jour,
     * qu'il vienne de variantes synchronisées ou d'un stock direct).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getLowStockCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::hasOutOfStockProducts() ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Produits en stock bas ou en rupture (≤ '.Product::LOW_STOCK_THRESHOLD.')';
    }

    private static function getLowStockCount(): int
    {
        return Product::query()
            ->where('stock', '<=', Product::LOW_STOCK_THRESHOLD)
            ->count();
    }

    private static function hasOutOfStockProducts(): bool
    {
        return Product::query()
            ->where('stock', '<=', 0)
            ->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
