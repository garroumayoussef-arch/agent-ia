<?php

namespace App\Filament\Resources\Invoices\Pages\Concerns;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Étape T24 — actions de création d'avoir, partagées par ViewInvoice
 * (aucune EditInvoice n'existe, la facture étant strictement en
 * lecture seule — T23).
 *
 * Gardées explicitement par InvoiceResource::canEdit() : jamais
 * accessible à un viewer, même sur cette page de consultation
 * autrement ouverte à tous sauf chauffeur — même choix délibéré que
 * generateInvoiceAction en T23 (émettre un document légal immuable est
 * jugé trop sensible pour reposer sur la seule protection indirecte de
 * l'accès à la page).
 *
 * Les DEUX actions ("total" et "partiel") délèguent entièrement la
 * validation métier à CreditNote::generateFromInvoice() — jamais
 * confiance dans la sélection venue du navigateur, revérifiée
 * intégralement côté modèle (cf. sa documentation : barrière à trois
 * niveaux contre le sur-crédit).
 */
trait HasInvoiceWorkflowActions
{
    /**
     * Point 3 (validé) — sélectionne AUTOMATIQUEMENT toutes les lignes
     * encore créditables, aucune coche manuelle requise. Invisible dès
     * qu'aucune ligne n'est plus créditable (facture déjà intégralement
     * créditée, ou aucune ligne du tout).
     */
    protected function generateTotalCreditNoteAction(): Action
    {
        return Action::make('generateTotalCreditNote')
            ->label('Créer un avoir total')
            ->icon('heroicon-o-receipt-refund')
            ->color('danger')
            ->visible(fn (Invoice $record): bool => InvoiceResource::canEdit($record)
                && CreditNote::creditableLinesFor($record)->isNotEmpty())
            ->requiresConfirmation()
            ->schema([
                Select::make('settlement_type')
                    ->label('Mode de règlement')
                    ->options([
                        CreditNote::SETTLEMENT_REFUND => 'Remboursement',
                        CreditNote::SETTLEMENT_FUTURE_INVOICE => 'Imputation sur facture future',
                    ])
                    ->required(),
                Textarea::make('reason')
                    ->label("Motif de l'avoir")
                    ->required()
                    ->columnSpanFull(),
            ])
            ->action(function (Invoice $record, array $data) {
                try {
                    $lineIds = CreditNote::creditableLinesFor($record)->pluck('id')->all();

                    $creditNote = CreditNote::generateFromInvoice(
                        $record,
                        $lineIds,
                        $data['reason'],
                        $data['settlement_type'],
                    );

                    Notification::make()
                        ->title("Avoir {$creditNote->number} créé")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title("Création de l'avoir impossible")
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Point 3 (validé) — sélection MANUELLE d'un sous-ensemble de
     * lignes entières parmi celles encore créditables (D4 T24 :
     * jamais une quantité partielle au sein d'une ligne).
     */
    protected function generatePartialCreditNoteAction(): Action
    {
        return Action::make('generatePartialCreditNote')
            ->label('Créer un avoir partiel')
            ->icon('heroicon-o-receipt-percent')
            ->color('warning')
            ->visible(fn (Invoice $record): bool => InvoiceResource::canEdit($record)
                && CreditNote::creditableLinesFor($record)->isNotEmpty())
            ->schema(function (Invoice $record): array {
                $creditableLines = CreditNote::creditableLinesFor($record);

                return [
                    CheckboxList::make('invoice_line_ids')
                        ->label('Lignes à créditer')
                        ->options($creditableLines->mapWithKeys(
                            fn (InvoiceLine $line): array => [$line->id => static::creditNoteLineLabel($line)]
                        ))
                        ->required()
                        ->columnSpanFull(),
                    Select::make('settlement_type')
                        ->label('Mode de règlement')
                        ->options([
                            CreditNote::SETTLEMENT_REFUND => 'Remboursement',
                            CreditNote::SETTLEMENT_FUTURE_INVOICE => 'Imputation sur facture future',
                        ])
                        ->required(),
                    Textarea::make('reason')
                        ->label("Motif de l'avoir")
                        ->required()
                        ->columnSpanFull(),
                ];
            })
            ->action(function (Invoice $record, array $data) {
                try {
                    $creditNote = CreditNote::generateFromInvoice(
                        $record,
                        $data['invoice_line_ids'] ?? [],
                        $data['reason'],
                        $data['settlement_type'],
                    );

                    Notification::make()
                        ->title("Avoir {$creditNote->number} créé")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title("Création de l'avoir impossible")
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function creditNoteLineLabel(InvoiceLine $line): string
    {
        $label = $line->product_name;

        if ($line->variant_description) {
            $label .= " ({$line->variant_description})";
        }

        $label .= " — qté {$line->quantity} — ".number_format((float) $line->total_ttc, 2, ',', ' ').' € TTC';

        return $label;
    }
}
