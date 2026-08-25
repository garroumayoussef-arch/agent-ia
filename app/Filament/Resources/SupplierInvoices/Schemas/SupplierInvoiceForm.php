<?php

namespace App\Filament\Resources\SupplierInvoices\Schemas;

use App\Models\PurchaseOrder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Étape T28 — formulaire de saisie d'une facture fournisseur reçue.
 * Aucune valeur n'est précalculée depuis le bon de commande (un
 * fournisseur peut facturer un montant différent) : total_ht/
 * tax_amount/total_ttc sont des champs saisis, pas dérivés.
 *
 * Le Select purchase_order_id n'affiche volontairement que les bons
 * de commande "commandé"/"partiellement reçu"/"reçu" (jamais brouillon
 * ni annulé) — même barrière que la vérification côté modèle
 * (SupplierInvoice::booted()), ici en confort d'UI, jamais la seule
 * protection.
 */
class SupplierInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Select::make('supplier_id')
                    ->label('Fournisseur')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('purchase_order_id')
                    ->label('Bon de commande')
                    ->options(fn () => PurchaseOrder::query()
                        ->whereIn('status', [
                            PurchaseOrder::STATUS_ORDERED,
                            PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                            PurchaseOrder::STATUS_RECEIVED,
                        ])
                        ->orderByDesc('created_at')
                        ->pluck('reference', 'id')
                        ->toArray())
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('supplier_invoice_number')
                    ->label('Numéro de facture (fournisseur)')
                    ->required(),

                DatePicker::make('invoice_date')
                    ->label('Date de la facture')
                    ->required(),

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
                    ->prefix('€')
                    ->required(),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),

            ]);
    }
}
