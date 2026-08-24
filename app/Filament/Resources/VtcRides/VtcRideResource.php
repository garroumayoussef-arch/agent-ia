<?php

namespace App\Filament\Resources\VtcRides;

use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Resources\VtcRides\Pages\CreateVtcRide;
use App\Filament\Resources\VtcRides\Pages\EditVtcRide;
use App\Filament\Resources\VtcRides\Pages\ListVtcRides;
use App\Filament\Resources\VtcRides\Pages\ViewVtcRide;
use App\Filament\Resources\VtcRides\Schemas\VtcRideForm;
use App\Filament\Resources\VtcRides\Schemas\VtcRideInfolist;
use App\Filament\Resources\VtcRides\Tables\VtcRidesTable;
use App\Models\VtcRide;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class VtcRideResource extends Resource
{
    use HasRoleBasedAuthorization;

    protected static ?string $model = VtcRide::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $modelLabel = 'Course VTC';

    protected static ?string $pluralModelLabel = 'Courses VTC';

    protected static ?string $navigationLabel = 'Courses VTC';

    public static function form(Schema $schema): Schema
    {
        return VtcRideForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return VtcRideInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VtcRidesTable::configure($table);
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
            'index' => ListVtcRides::route('/'),
            'create' => CreateVtcRide::route('/create'),
            'view' => ViewVtcRide::route('/{record}'),
            'edit' => EditVtcRide::route('/{record}/edit'),
        ];
    }
}
