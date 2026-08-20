<?php

namespace App\Filament\Resources\SalesOrders;

use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Filament\Resources\SalesOrders\Schemas\SalesOrderForm;
use App\Filament\Resources\SalesOrders\Schemas\SalesOrderInfolist;
use App\Filament\Resources\SalesOrders\Tables\SalesOrdersTable;
use App\Models\SalesOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SalesOrderResource extends Resource
{
    use HasRoleBasedAuthorization;

    protected static ?string $model = SalesOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'Commande';

    protected static ?string $pluralModelLabel = 'Ventes';

    protected static ?string $navigationLabel = 'Ventes';

    /**
     * Pastille de compteur : commandes en attente d'action (confirmées
     * ou partiellement expédiées).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = SalesOrder::query()
            ->whereIn('status', [
                SalesOrder::STATUS_CONFIRMED,
                SalesOrder::STATUS_PARTIALLY_SHIPPED,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function form(Schema $schema): Schema
    {
        return SalesOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalesOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesOrdersTable::configure($table);
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
            'index' => ListSalesOrders::route('/'),
            'create' => CreateSalesOrder::route('/create'),
            'view' => ViewSalesOrder::route('/{record}'),
            'edit' => EditSalesOrder::route('/{record}/edit'),
        ];
    }
}
