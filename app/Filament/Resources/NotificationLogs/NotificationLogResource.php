<?php

namespace App\Filament\Resources\NotificationLogs;

use App\Filament\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Filament\Resources\NotificationLogs\Pages\ViewNotificationLog;
use App\Filament\Resources\NotificationLogs\Tables\NotificationLogsTable;
use App\Models\NotificationLog;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Chantier "Notifications & communication" V1 (D9/D10, validés) —
 * consultation du journal des envois (email externe client/fournisseur,
 * digest interne stock bas), strictement en LECTURE SEULE — aucune page
 * create/edit/delete, même principe que InvoiceResource/
 * WarehouseStockResource — et réservée à l'admin, y compris en lecture
 * (même principe que UserResource, jamais HasRoleBasedAuthorization qui
 * ouvrirait la lecture à tous) : ce journal expose des adresses email
 * de tiers, une donnée sensible.
 *
 * Infolist défini directement ici plutôt que dans un fichier Schemas/
 * dédié : périmètre V1 strictement limité aux 30 fichiers validés,
 * simple exception ponctuelle au style habituel de ce projet, pas un
 * changement de convention pour les Resources futures.
 */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Journal des notifications';

    protected static ?string $modelLabel = 'Notification';

    protected static ?string $pluralModelLabel = 'Notifications';

    public static function canViewAny(): bool
    {
        return static::isAdmin();
    }

    public static function canView(Model $record): bool
    {
        return static::isAdmin();
    }

    private static function isAdmin(): bool
    {
        return Auth::user()?->hasRole('admin') ?? false;
    }

    public static function table(Table $table): Table
    {
        return NotificationLogsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Notification')
                ->columns(2)
                ->schema([
                    TextEntry::make('event_type')->label('Événement'),
                    TextEntry::make('channel')->label('Canal'),
                    TextEntry::make('notifiable_type')
                        ->label('Type de document')
                        ->formatStateUsing(fn (string $state): string => class_basename($state)),
                    TextEntry::make('notifiable_id')->label('Référence'),
                    TextEntry::make('recipient_address')->label('Destinataire')->placeholder('-'),
                    TextEntry::make('status')->label('Statut')->badge(),
                    TextEntry::make('sent_at')->label('Envoyée le')->dateTime('d/m/Y H:i')->placeholder('-'),
                    TextEntry::make('triggeredBy.name')->label('Déclenchée par')->placeholder('Système'),
                    TextEntry::make('error_message')->label('Erreur')->placeholder('-')->columnSpanFull(),
                    TextEntry::make('created_at')->label('Créée le')->dateTime('d/m/Y H:i'),
                ]),
        ]);
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
            'index' => ListNotificationLogs::route('/'),
            'view' => ViewNotificationLog::route('/{record}'),
        ];
    }
}
