<?php

namespace App\Filament\Resources\SupplierInvoices\Pages\Concerns;

use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoicePayment;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Étape T30 — action "Enregistrer un paiement" sur ViewSupplierInvoice.
 * Délègue entièrement la validation métier à
 * SupplierInvoicePayment::recordFor() — jamais confiance dans les
 * valeurs venues du navigateur, revérifiées intégralement côté modèle
 * (montant > 0, cumul jamais supérieur au total TTC, sous transaction
 * et verrouillage).
 *
 * ->authorize() en plus de ->visible() (leçon T25/T26) : Filament
 * résout une action en appelant directement sa méthode PHP, sans
 * jamais consulter isVisible() lors d'un appel Livewire direct/forgé —
 * ->authorize() est réellement évalué côté serveur à chaque montage/
 * exécution de l'action, y compris un tel appel direct.
 */
trait HasSupplierInvoicePaymentAction
{
    protected function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Enregistrer un paiement')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            // Masqué dès que la facture est intégralement payée : aucune
            // raison de proposer un nouveau paiement sur un solde nul,
            // même principe que CreditNote::creditableLinesFor()->isNotEmpty().
            ->visible(fn (SupplierInvoice $record): bool => SupplierInvoiceResource::canEdit($record)
                && $record->paymentStatus() !== SupplierInvoice::PAYMENT_STATUS_PAID)
            ->authorize(fn (SupplierInvoice $record): bool => SupplierInvoiceResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage('Cette action est réservée aux administrateurs et gestionnaires.')
            ->schema([
                TextInput::make('amount')
                    ->label('Montant du paiement')
                    ->numeric()
                    ->minValue(0.01)
                    ->prefix('€')
                    ->required(),

                DatePicker::make('paid_at')
                    ->label('Date du paiement')
                    ->required()
                    ->default(now()->toDateString()),

                TextInput::make('reference')
                    ->label('Référence (optionnelle)')
                    ->maxLength(255),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->action(function (SupplierInvoice $record, array $data) {
                try {
                    SupplierInvoicePayment::recordFor(
                        $record,
                        (float) $data['amount'],
                        $data['paid_at'],
                        $data['reference'] ?? null,
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title('Paiement enregistré')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title("Enregistrement du paiement impossible")
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
