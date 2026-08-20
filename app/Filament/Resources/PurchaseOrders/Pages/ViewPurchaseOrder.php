<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\Pages\Concerns\HasPurchaseOrderWorkflowActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    use HasPurchaseOrderWorkflowActions;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmOrderAction(),
            $this->receiveOrderAction(),
            $this->cancelOrderAction(),
            EditAction::make(),
        ];
    }
}
