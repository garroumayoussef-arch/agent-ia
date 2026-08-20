<?php

namespace App\Filament\Resources\ProductVariants;

use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Resources\ProductVariants\Pages\CreateProductVariant;
use App\Filament\Resources\ProductVariants\Pages\EditProductVariant;
use App\Filament\Resources\ProductVariants\Pages\ListProductVariants;
use App\Filament\Resources\ProductVariants\Schemas\ProductVariantForm;
use App\Filament\Resources\ProductVariants\Tables\ProductVariantsTable;
use App\Models\Product;
use App\Models\ProductVariant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductVariantResource extends Resource
{
    use HasRoleBasedAuthorization;

    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?string $recordTitleAttribute = 'sku';

    protected static ?string $modelLabel = 'Variante';

    protected static ?string $pluralModelLabel = 'Variantes';

    protected static ?string $navigationLabel = 'Variantes';

    /**
     * Pastille de compteur sur le menu "Variantes" : nombre de
     * variantes en stock bas ou en rupture.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getLowStockCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::hasOutOfStockVariants() ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Variantes en stock bas ou en rupture (≤ '.Product::LOW_STOCK_THRESHOLD.')';
    }

    private static function getLowStockCount(): int
    {
        return ProductVariant::query()
            ->where('stock', '<=', Product::LOW_STOCK_THRESHOLD)
            ->count();
    }

    private static function hasOutOfStockVariants(): bool
    {
        return ProductVariant::query()
            ->where('stock', '<=', 0)
            ->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return ProductVariantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductVariantsTable::configure($table);
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
            'index' => ListProductVariants::route('/'),
            'create' => CreateProductVariant::route('/create'),
            'edit' => EditProductVariant::route('/{record}/edit'),
        ];
    }
}
