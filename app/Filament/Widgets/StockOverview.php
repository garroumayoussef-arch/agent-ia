<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\StockMovement;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StockOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Produits', Product::count())
                ->description('Produits enregistrés')
                ->color('success'),

            Stat::make('Stock total', Product::sum('stock'))
                ->description('Articles en stock')
                ->color('primary'),

            Stat::make('Fournisseurs', Supplier::count())
                ->description('Fournisseurs actifs')
                ->color('warning'),

            Stat::make('Mouvements', StockMovement::count())
                ->description('Entrées / Sorties')
                ->color('danger'),
        ];
    }
}