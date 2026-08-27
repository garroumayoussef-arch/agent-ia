<?php

namespace App\Filament\Resources\SupplierInvoices\Tables;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Filament\Actions\ViewAction;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Étape T28 — aucune EditAction/DeleteAction enregistrée ici (facture
 * fournisseur immuable dès l'enregistrement, cf. SupplierInvoice::booted())
 * — même principe que InvoicesTable/CreditNotesTable (T23/T24) :
 * ViewAction seule, jamais de bouton menant à une opération que le
 * modèle refuserait de toute façon.
 */
class SupplierInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier_invoice_number')
                    ->label('Numéro de facture')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Fournisseur')
                    ->searchable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('purchaseOrder.reference')
                    ->label('Bon de commande')
                    ->searchable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('invoice_date')
                    ->label('Date de la facture')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_ttc')
                    ->label('Total TTC')
                    ->money('EUR')
                    ->sortable(),

                // Étape T30 — toujours recalculé depuis
                // SupplierInvoice::paymentStatus(), jamais un champ
                // stocké (cf. sa documentation).
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Paiement')
                    ->state(fn (SupplierInvoice $record): string => $record->paymentStatus())
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::paymentStatusLabel($state))
                    ->color(fn (string $state): string => static::paymentStatusColor($state)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Enregistrée le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Fournisseur')
                    ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id')->toArray())
                    ->searchable(),

                // Chantier A — payment_status est une colonne CALCULÉE
                // (jamais stockée, cf. SupplierInvoice::paymentStatus()),
                // donc non filtrable par une clause WHERE directe sur un
                // champ. Symétrique exact du filtre sur InvoicesTable :
                // sous-requête CORRÉLÉE dans le WHERE (jamais un
                // withSum()+having(), rejeté par SQLite avec "HAVING
                // clause on a non-aggregate query", vérifié
                // empiriquement). COALESCE(..., 0) gère nativement
                // l'absence de paiement/d'avoir, ROUND(..., 2) neutralise
                // le bruit flottant de l'affinité NUMERIC de SQLite sur
                // les colonnes decimal(10,2).
                //
                // Chantier "avoir fournisseur" (réconciliation) — même
                // formule NETTE (total_ttc − avoirs − paiements) et mêmes
                // 4 seuils exacts que SupplierInvoice::paymentStatus(),
                // reproduits ici à l'identique (même convention que
                // InvoicesTable : duplication contrôlée assumée, aucun
                // helper partagé) — jamais une deuxième définition du
                // statut qui pourrait diverger de la première.
                SelectFilter::make('payment_status')
                    ->label('Statut de paiement')
                    ->options([
                        SupplierInvoice::PAYMENT_STATUS_UNPAID => 'Non payée',
                        SupplierInvoice::PAYMENT_STATUS_PARTIAL => 'Partiellement payée',
                        SupplierInvoice::PAYMENT_STATUS_PAID => 'Payée',
                        SupplierInvoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE => 'Soldée par avoir',
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $status = $data['value'] ?? null;

                        if (blank($status)) {
                            return;
                        }

                        $paidExpr = 'ROUND((select coalesce(sum(amount), 0) from supplier_invoice_payments '
                            .'where supplier_invoice_payments.supplier_invoice_id = supplier_invoices.id), 2)';
                        // Avoirs liés à CETTE facture, même sous-requête
                        // corrélée que $paidExpr.
                        $creditedExpr = 'ROUND((select coalesce(sum(total_ttc), 0) from supplier_credit_notes '
                            .'where supplier_credit_notes.supplier_invoice_id = supplier_invoices.id), 2)';
                        $netTotalExpr = "ROUND(total_ttc - ({$creditedExpr}), 2)";

                        match ($status) {
                            // Net à 0 par avoir, SANS aucun paiement réel
                            // (credited > 0 exclut le cas marginal d'une
                            // facture à 0 € sans avoir, cf.
                            // SupplierInvoice::paymentStatus()).
                            SupplierInvoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE => $query
                                ->whereRaw("{$paidExpr} <= 0")
                                ->whereRaw("{$netTotalExpr} <= 0")
                                ->whereRaw("({$creditedExpr}) > 0"),
                            // Exclut explicitement le cas ci-dessus :
                            // paid<=0 ET net<=0 ET credited>0 relève de
                            // soldee_par_avoir, jamais de non_payee (même
                            // ordre de priorité que le match PHP).
                            SupplierInvoice::PAYMENT_STATUS_UNPAID => $query
                                ->whereRaw("{$paidExpr} <= 0")
                                ->whereRaw("({$netTotalExpr} > 0 OR ({$creditedExpr}) <= 0)"),
                            SupplierInvoice::PAYMENT_STATUS_PAID => $query
                                ->whereRaw("{$paidExpr} > 0")
                                ->whereRaw("{$paidExpr} >= {$netTotalExpr}"),
                            SupplierInvoice::PAYMENT_STATUS_PARTIAL => $query
                                ->whereRaw("{$paidExpr} > 0")
                                ->whereRaw("{$paidExpr} < {$netTotalExpr}"),
                            default => null,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            SupplierInvoice::PAYMENT_STATUS_UNPAID => 'Non payée',
            SupplierInvoice::PAYMENT_STATUS_PARTIAL => 'Partiellement payée',
            SupplierInvoice::PAYMENT_STATUS_PAID => 'Payée',
            SupplierInvoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE => 'Soldée par avoir',
            default => $status,
        };
    }

    public static function paymentStatusColor(string $status): string
    {
        return match ($status) {
            SupplierInvoice::PAYMENT_STATUS_UNPAID => 'danger',
            SupplierInvoice::PAYMENT_STATUS_PARTIAL => 'warning',
            SupplierInvoice::PAYMENT_STATUS_PAID => 'success',
            // Distincte de "payée" (success) : aucun décaissement réel
            // n'a eu lieu, jamais la même couleur qu'un vrai paiement
            // (même convention qu'Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE).
            SupplierInvoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE => 'gray',
            default => 'gray',
        };
    }
}
