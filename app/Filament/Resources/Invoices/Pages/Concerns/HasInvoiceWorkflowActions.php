<?php

namespace App\Filament\Resources\Invoices\Pages\Concerns;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoicePayment;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
 *
 * Étape T25-B — ->visible() ne bloque que l'affichage du bouton :
 * Filament résout une action en appelant directement sa méthode PHP
 * (resolveAction()), sans jamais consulter isVisible() — un appel
 * Livewire direct/forgé pouvait donc jusqu'ici contourner totalement
 * cette garde (démontré empiriquement en T24). ->authorize() est en
 * revanche réellement évalué côté serveur à chaque montage/exécution de
 * l'action (mountAction()/callMountedAction()), y compris un tel appel
 * direct : c'est la barrière ajoutée ici, en plus de ->visible() qui
 * reste inchangée (comportement d'affichage identique à avant).
 *
 * Étape T31 — recordPaymentAction() : suivi des paiements clients,
 * symétrique exact de HasSupplierInvoicePaymentAction (T30). Délègue
 * entièrement la validation à InvoicePayment::recordFor() (montant > 0,
 * jamais de dépassement du solde, transaction + verrouillage). Même
 * garde ->visible()/->authorize() que les deux actions d'avoir
 * ci-dessus, masquée dès que paymentStatus() === PAYMENT_STATUS_PAID.
 *
 * Chantier "réconciliation avoirs" (D4, validé) — CreditNote::
 * generateFromInvoice() reste volontairement libre (aucune validation
 * ajoutée là-bas, jamais bloquant) : le modèle n'importe aucune classe
 * Filament, comme tous les modèles de ce projet. C'est ICI, seul
 * endroit où Notification::make() est déjà utilisé, qu'un avertissement
 * (jamais un blocage) est ajouté après la création réussie d'un avoir,
 * lorsqu'il laisse un solde créditeur (cf. notifyIfCreditBalanceGenerated()
 * ci-dessous, qui réutilise Invoice::creditBalance() — D3, déjà
 * implémentée et testée — aucune nouvelle logique de calcul ici).
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
            ->authorize(fn (Invoice $record): bool => InvoiceResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
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

                    static::notifyIfCreditBalanceGenerated($record);
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
            ->authorize(fn (Invoice $record): bool => InvoiceResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
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

                    static::notifyIfCreditBalanceGenerated($record);
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
     * Étape T31 — enregistrement d'un paiement client. Masquée dès que
     * la facture est intégralement payée : aucune raison de proposer un
     * nouveau paiement sur un solde nul, même principe que
     * generateTotalCreditNoteAction()/generatePartialCreditNoteAction()
     * masquées dès qu'aucune ligne n'est plus créditable.
     */
    protected function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Enregistrer un paiement')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Invoice $record): bool => InvoiceResource::canEdit($record)
                && $record->paymentStatus() !== Invoice::PAYMENT_STATUS_PAID)
            ->authorize(fn (Invoice $record): bool => InvoiceResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage("Cette action est réservée aux administrateurs et gestionnaires.")
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
            ->action(function (Invoice $record, array $data) {
                try {
                    InvoicePayment::recordFor(
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

    /**
     * D4 (validé) — avertit, SANS JAMAIS bloquer, lorsque l'avoir qui
     * vient d'être créé laisse un solde créditeur (paiements déjà
     * encaissés dépassant le nouveau montant net après avoir, cf.
     * Invoice::creditBalance() — D3, déjà implémentée et testée à
     * l'étape 1). Appelée uniquement APRÈS la création réussie de
     * l'avoir (jamais avant, jamais comme condition de blocage) : ce
     * n'est en aucun cas une validation, seulement une information
     * pour l'utilisateur, cohérent avec le choix D4 explicitement
     * validé ("ne pas bloquer la création de l'avoir").
     *
     * $record est relu fraîchement (fresh()) : creditBalance() dépend
     * des avoirs et paiements en base à l'instant présent, jamais
     * d'une valeur potentiellement obsolète sur l'instance déjà en
     * mémoire.
     */
    private static function notifyIfCreditBalanceGenerated(Invoice $record): void
    {
        $creditBalance = $record->fresh()->creditBalance();

        if ($creditBalance <= 0) {
            return;
        }

        Notification::make()
            ->title('Solde créditeur généré')
            ->body(
                'Cet avoir laisse un solde créditeur de '
                .number_format($creditBalance, 2, ',', ' ')
                .' € : un remboursement est dû au client.'
            )
            ->warning()
            ->persistent()
            ->send();
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
