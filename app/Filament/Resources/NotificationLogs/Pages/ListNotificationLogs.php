<?php

namespace App\Filament\Resources\NotificationLogs\Pages;

use App\Filament\Resources\NotificationLogs\NotificationLogResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationLogs extends ListRecords
{
    protected static string $resource = NotificationLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
