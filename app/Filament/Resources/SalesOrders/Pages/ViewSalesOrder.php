<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Filament\Resources\SalesOrders\Pages\Concerns\HasSalesOrderWorkflowActions;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesOrder extends ViewRecord
{
    use HasSalesOrderWorkflowActions;

    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmOrderAction(),
            $this->shipOrderAction(),
            $this->cancelOrderAction(),
            EditAction::make(),
        ];
    }
}
