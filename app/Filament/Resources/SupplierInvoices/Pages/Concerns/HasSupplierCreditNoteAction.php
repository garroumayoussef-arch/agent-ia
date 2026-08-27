<?php

namespace App\Filament\Resources\SupplierInvoices\Pages\Concerns;

use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Models\SupplierCreditNote;
use App\Models\SupplierInvoice;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Chantier "avoir fournisseur" — action "Enregistrer un avoir" sur
 * ViewSupplierInvoice. Délègue entièrement la validation métier à
 * SupplierCreditNote::recordFor() — jamais confiance dans les valeurs
 * venues du navigateur, revérifiées intégralement côté modèle (numéro
 * renseigné, montant > 0, cumul jamais supérieur au total TTC, sous
 * transaction et verrouillage). Même patron que
 * HasSupplierInvoicePaymentAction (T30).
 *
 * ->authorize() en plus de ->visible() (leçon T25/T26) : Filament
 * résout une action en appelant directement sa méthode PHP, sans
 * jamais consulter isVisible() lors d'un appel Livewire direct/forgé —
 * ->authorize() est réellement évaluée côté serveur à chaque montage/
 * exécution de l'action, y compris un tel appel direct.
 */
trait HasSupplierCreditNoteAction
{
    protected function recordSupplierCreditNoteAction(): Action
    {
        return Action::make('recordSupplierCreditNote')
            ->label('Enregistrer un avoir')
            ->icon('heroicon-o-receipt-refund')
            ->color('warning')
            // Masqué dès que la facture est intégralement créditée :
            // aucune raison de proposer un nouvel avoir sur un solde
            // créditable nul, même principe que
            // HasSupplierInvoicePaymentAction::recordPaymentAction().
            ->visible(fn (SupplierInvoice $record): bool => SupplierInvoiceResource::canEdit($record)
                && SupplierCreditNote::totalCreditedFor($record) < round((float) $record->total_ttc, 2))
            ->authorize(fn (SupplierInvoice $record): bool => SupplierInvoiceResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage('Cette action est réservée aux administrateurs et gestionnaires.')
            ->schema([
                TextInput::make('supplier_credit_note_number')
                    ->label("Numéro de l'avoir (fournisseur)")
                    ->required(),

                DatePicker::make('credit_note_date')
                    ->label("Date de l'avoir")
                    ->required()
                    ->default(now()->toDateString()),

                TextInput::make('total_ht')
                    ->label('Total HT')
                    ->numeric()
                    ->prefix('€')
                    ->required(),

                TextInput::make('tax_amount')
                    ->label('Montant TVA')
                    ->numeric()
                    ->prefix('€')
                    ->required(),

                TextInput::make('total_ttc')
                    ->label('Total TTC')
                    ->numeric()
                    ->minValue(0.01)
                    ->prefix('€')
                    ->required(),

                Textarea::make('reason')
                    ->label('Motif (optionnel)')
                    ->rows(2)
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->action(function (SupplierInvoice $record, array $data) {
                try {
                    SupplierCreditNote::recordFor(
                        $record,
                        $data['supplier_credit_note_number'],
                        $data['credit_note_date'],
                        (float) $data['total_ht'],
                        (float) $data['tax_amount'],
                        (float) $data['total_ttc'],
                        $data['reason'] ?? null,
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title('Avoir enregistré')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title("Enregistrement de l'avoir impossible")
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
