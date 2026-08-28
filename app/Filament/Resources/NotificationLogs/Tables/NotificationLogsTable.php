<?php

namespace App\Filament\Resources\NotificationLogs\Tables;

use App\Models\NotificationLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Chantier "Notifications & communication" V1 (D9, validé) — liste en
 * lecture seule, aucune action de mutation (recordActions ne contient
 * que ViewAction).
 */
class NotificationLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('event_type')
                    ->label('Événement')
                    ->formatStateUsing(fn (string $state): string => self::eventTypeLabel($state))
                    ->searchable(),

                TextColumn::make('channel')->label('Canal'),

                TextColumn::make('recipient_address')
                    ->label('Destinataire')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        NotificationLog::STATUS_SENT => 'success',
                        NotificationLog::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('sent_at')
                    ->label('Envoyée le')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        NotificationLog::STATUS_QUEUED => 'En file',
                        NotificationLog::STATUS_SENT => 'Envoyée',
                        NotificationLog::STATUS_FAILED => 'Échec',
                    ]),

                SelectFilter::make('event_type')
                    ->label('Événement')
                    ->options([
                        'invoice_issued' => 'Facture émise',
                        'credit_note_issued' => 'Avoir émis',
                        'customer_return_issued' => 'Retour client',
                        'supplier_return_issued' => 'Retour fournisseur',
                        'low_stock_digest' => 'Alerte stock bas',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function eventTypeLabel(string $eventType): string
    {
        return match ($eventType) {
            'invoice_issued' => 'Facture émise',
            'credit_note_issued' => 'Avoir émis',
            'customer_return_issued' => 'Retour client',
            'supplier_return_issued' => 'Retour fournisseur',
            'low_stock_digest' => 'Alerte stock bas',
            default => $eventType,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            NotificationLog::STATUS_QUEUED => 'En file',
            NotificationLog::STATUS_SENT => 'Envoyée',
            NotificationLog::STATUS_FAILED => 'Échec',
            default => $status,
        };
    }
}
