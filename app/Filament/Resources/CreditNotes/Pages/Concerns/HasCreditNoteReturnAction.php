<?php

namespace App\Filament\Resources\CreditNotes\Pages\Concerns;

use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\CreditNoteLineReturn;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Collection;

/**
 * Chantier "retour physique" (Option 3b, validée) — action de
 * déclaration d'un retour physique, entièrement SÉPARÉE et postérieure
 * dans le temps à la création de l'avoir (CreditNote::generateFromInvoice()
 * n'est ni modifiée ni appelée ici). Une CreditNote peut recevoir 0, 1,
 * ou plusieurs retours successifs, sur une ou plusieurs de ses lignes.
 *
 * Gardée par CreditNoteResource::canEdit() (admin/manager) — même
 * convention que HasInvoiceWorkflowActions (T24/T31) : ->authorize()
 * est réellement évaluée côté serveur à chaque montage/exécution de
 * l'action (T25-B), ->visible() ne fait que masquer le bouton.
 *
 * Toute la validation métier (quantité > 0, condition valide, produit/
 * variante identifiable, cumul jamais supérieur à la quantité créditée)
 * est déléguée intégralement à CreditNoteLineReturn::recordFor() —
 * jamais confiance dans la sélection venue du navigateur.
 */
trait HasCreditNoteReturnAction
{
    protected function recordReturnAction(): Action
    {
        return Action::make('recordCreditNoteReturn')
            ->label('Enregistrer un retour')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (CreditNote $record): bool => CreditNoteResource::canEdit($record)
                && static::returnableLinesFor($record)->isNotEmpty())
            ->authorize(fn (CreditNote $record): bool => CreditNoteResource::canEdit($record))
            ->authorizationNotification()
            ->authorizationMessage('Cette action est réservée aux administrateurs et gestionnaires.')
            ->requiresConfirmation()
            ->schema(function (CreditNote $record): array {
                $returnableLines = static::returnableLinesFor($record);

                return [
                    Select::make('credit_note_line_id')
                        ->label('Ligne concernée')
                        ->options($returnableLines->mapWithKeys(
                            fn (CreditNoteLine $line): array => [$line->id => static::returnLineLabel($line)]
                        ))
                        ->required()
                        ->live(),

                    TextInput::make('quantity')
                        ->label('Quantité retournée')
                        ->numeric()
                        ->minValue(1)
                        ->required()
                        ->helperText(function (Get $get) use ($returnableLines): ?string {
                            $selectedLineId = $get('credit_note_line_id');

                            if (blank($selectedLineId)) {
                                return null;
                            }

                            $line = $returnableLines->firstWhere('id', (int) $selectedLineId);

                            if (! $line) {
                                return null;
                            }

                            $remaining = $line->quantity - CreditNoteLineReturn::totalReturnedFor($line);

                            return "Quantité maximale retournable pour cette ligne : {$remaining}.";
                        }),

                    Radio::make('condition')
                        ->label('État du produit retourné')
                        ->options([
                            CreditNoteLineReturn::CONDITION_SELLABLE => 'Vendable',
                            CreditNoteLineReturn::CONDITION_DEFECTIVE => 'Défectueux',
                        ])
                        ->required(),

                    DatePicker::make('returned_at')
                        ->label('Date du retour')
                        ->required()
                        ->default(now()->toDateString()),

                    TextInput::make('reference')
                        ->label('Référence (optionnelle)')
                        ->maxLength(255),

                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ];
            })
            ->action(function (array $data) {
                try {
                    $line = CreditNoteLine::findOrFail($data['credit_note_line_id']);

                    $return = CreditNoteLineReturn::recordFor(
                        $line,
                        (int) $data['quantity'],
                        $data['condition'],
                        $data['returned_at'],
                        $data['reference'] ?? null,
                        $data['notes'] ?? null,
                    );

                    $body = $return->condition === CreditNoteLineReturn::CONDITION_SELLABLE
                        ? "{$return->quantity} unité(s) vendable(s) réintégrée(s) en stock."
                        : "{$return->quantity} unité(s) défectueuse(s) enregistrée(s), aucun impact sur le stock vendable.";

                    Notification::make()
                        ->title('Retour enregistré')
                        ->body($body)
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Enregistrement du retour impossible')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Lignes de CET avoir encore retournables — calculé à la demande,
     * jamais un champ stocké (même principe que
     * CreditNote::creditableLinesFor()). Exclut les lignes sans
     * produit/variante identifiable (règle définitive validée : un
     * retour, vendable ou défectueux, y est de toute façon toujours
     * refusé par recordFor()) et celles déjà intégralement retournées.
     *
     * @return Collection<int, CreditNoteLine>
     */
    private static function returnableLinesFor(CreditNote $record): Collection
    {
        return $record->lines()->get()->filter(function (CreditNoteLine $line): bool {
            if (blank($line->product_id) && blank($line->product_variant_id)) {
                return false;
            }

            return CreditNoteLineReturn::totalReturnedFor($line) < $line->quantity;
        })->values();
    }

    private static function returnLineLabel(CreditNoteLine $line): string
    {
        $label = $line->product_name;

        if ($line->variant_description) {
            $label .= " ({$line->variant_description})";
        }

        $alreadyReturned = CreditNoteLineReturn::totalReturnedFor($line);
        $label .= " — {$alreadyReturned}/{$line->quantity} déjà retourné(s)";

        return $label;
    }
}
