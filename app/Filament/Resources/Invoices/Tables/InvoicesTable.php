<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Étape T23 — table strictement en lecture : aucune colonne d'action
 * de mutation (pas de recordActions d'édition/suppression) — cohérent
 * avec InvoiceResource, qui ne déclare aucune page create/edit.
 */
class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('N° facture')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Client')
                    ->searchable(),

                TextColumn::make('customer_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'business' ? 'Professionnel' : 'Particulier')
                    ->color(fn (string $state): string => $state === 'business' ? 'warning' : 'gray'),

                // Chantier "facturation légale VTC" (D4, validé) —
                // colonne calculée : une facture a désormais deux
                // origines possibles (D1), jamais affichées dans deux
                // colonnes séparées. searchable() sur les deux colonnes
                // brutes sous-jacentes (jamais sur le libellé calculé,
                // introuvable par une recherche SQL directe).
                TextColumn::make('origin_label')
                    ->label('Origine')
                    ->state(fn (Invoice $record): string => $record->originLabel())
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query
                            ->whereHas('salesOrder', fn (Builder $q) => $q->where('reference', 'like', "%{$search}%"))
                            ->orWhereHas('vtcRide', fn (Builder $q) => $q->where('reference', 'like', "%{$search}%"));
                    }),

                TextColumn::make('issued_at')
                    ->label('Émise le')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('total_ttc')
                    ->label('Total TTC')
                    ->money('EUR')
                    ->sortable(),

                // Étape T31 — toujours recalculé depuis
                // Invoice::paymentStatus(), jamais un champ stocké (cf.
                // sa documentation).
                TextColumn::make('payment_status')
                    ->label('Paiement')
                    ->state(fn (Invoice $record): string => $record->paymentStatus())
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::paymentStatusLabel($state))
                    ->color(fn (string $state): string => static::paymentStatusColor($state)),
            ])
            ->filters([
                SelectFilter::make('customer_type')
                    ->label('Type de client')
                    ->options([
                        'individual' => 'Particulier',
                        'business' => 'Professionnel',
                    ]),

                // Chantier A — payment_status est une colonne CALCULÉE
                // (jamais stockée, cf. Invoice::paymentStatus()), donc
                // non filtrable par une clause WHERE directe sur un
                // champ. Comparaison via une sous-requête CORRÉLÉE dans
                // le WHERE (jamais un withSum()+having() : rejeté par
                // SQLite avec "HAVING clause on a non-aggregate query",
                // vérifié empiriquement — SQLite n'autorise HAVING que
                // sur une requête avec un agrégat/GROUP BY réel).
                // COALESCE(..., 0) gère nativement l'absence de paiement
                // /d'avoir (NULL), ROUND(..., 2) neutralise le bruit
                // flottant de l'affinité NUMERIC de SQLite sur les
                // colonnes decimal(10,2).
                //
                // Chantier "réconciliation avoirs" (D1/D2/D6, validés) —
                // même formule NETTE (total_ttc − avoirs − paiements) et
                // mêmes 4 seuils exacts que Invoice::paymentStatus(),
                // reproduits ici à l'identique (D6 : duplication
                // contrôlée assumée, aucun helper partagé) — jamais une
                // deuxième définition du statut qui pourrait diverger de
                // la première.
                SelectFilter::make('payment_status')
                    ->label('Statut de paiement')
                    ->options([
                        Invoice::PAYMENT_STATUS_UNPAID => 'Non payée',
                        Invoice::PAYMENT_STATUS_PARTIAL => 'Partiellement payée',
                        Invoice::PAYMENT_STATUS_PAID => 'Payée',
                        Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE => 'Soldée par avoir',
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $status = $data['value'] ?? null;

                        if (blank($status)) {
                            return;
                        }

                        $paidExpr = 'ROUND((select coalesce(sum(amount), 0) from invoice_payments '
                            .'where invoice_payments.invoice_id = invoices.id), 2)';
                        // D1 (validé) — avoirs liés à CETTE facture,
                        // même sous-requête corrélée que $paidExpr.
                        $creditedExpr = 'ROUND((select coalesce(sum(total_ttc), 0) from credit_notes '
                            .'where credit_notes.invoice_id = invoices.id), 2)';
                        $netTotalExpr = "ROUND(total_ttc - ({$creditedExpr}), 2)";

                        match ($status) {
                            // D2 (validé) — net à 0 par avoir, SANS
                            // aucun paiement réel (credited > 0 exclut le
                            // cas marginal d'une facture à 0 € sans
                            // avoir, cf. Invoice::paymentStatus()).
                            Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE => $query
                                ->whereRaw("{$paidExpr} <= 0")
                                ->whereRaw("{$netTotalExpr} <= 0")
                                ->whereRaw("({$creditedExpr}) > 0"),
                            // Exclut explicitement le cas ci-dessus :
                            // paid<=0 ET net<=0 ET credited>0 relève de
                            // soldee_par_avoir, jamais de non_payee (même
                            // ordre de priorité que le match PHP).
                            Invoice::PAYMENT_STATUS_UNPAID => $query
                                ->whereRaw("{$paidExpr} <= 0")
                                ->whereRaw("({$netTotalExpr} > 0 OR ({$creditedExpr}) <= 0)"),
                            Invoice::PAYMENT_STATUS_PAID => $query
                                ->whereRaw("{$paidExpr} > 0")
                                ->whereRaw("{$paidExpr} >= {$netTotalExpr}"),
                            Invoice::PAYMENT_STATUS_PARTIAL => $query
                                ->whereRaw("{$paidExpr} > 0")
                                ->whereRaw("{$paidExpr} < {$netTotalExpr}"),
                            default => null,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('downloadPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn ($record): string => route('invoices.pdf', $record))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            Invoice::PAYMENT_STATUS_UNPAID => 'Non payée',
            Invoice::PAYMENT_STATUS_PARTIAL => 'Partiellement payée',
            Invoice::PAYMENT_STATUS_PAID => 'Payée',
            Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE => 'Soldée par avoir',
            default => $status,
        };
    }

    public static function paymentStatusColor(string $status): string
    {
        return match ($status) {
            Invoice::PAYMENT_STATUS_UNPAID => 'danger',
            Invoice::PAYMENT_STATUS_PARTIAL => 'warning',
            Invoice::PAYMENT_STATUS_PAID => 'success',
            // D2 (validé) — distincte de "payée" (success) : aucun
            // encaissement réel n'a eu lieu, jamais la même couleur
            // qu'un vrai paiement.
            Invoice::PAYMENT_STATUS_SETTLED_BY_CREDIT_NOTE => 'gray',
            default => 'gray',
        };
    }
}
