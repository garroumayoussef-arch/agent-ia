<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Concerns\BlocksChauffeurReadAccess;
use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Étape T23 — consultation des factures émises, strictement en LECTURE
 * SEULE : une facture est immuable (cf. Invoice::booted()), il n'existe
 * donc volontairement aucune page create/edit ici — même principe que
 * WarehouseStockResource (T14). La création passe exclusivement par
 * Invoice::generateFromSalesOrder(), via l'action dédiée sur
 * SalesOrderResource.
 *
 * D4 — HORS scoping entrepôt (ScopesToOwnWarehouses n'est PAS composé
 * ici) : une facture est rattachée à une SalesOrder, pas à un
 * entrepôt. Elle suit exactement les mêmes règles d'autorisation que
 * SalesOrderResource (BlocksChauffeurReadAccess uniquement) — ne
 * modifie aucune règle de scoping T20/T21 déjà validée.
 *
 * HasRoleBasedAuthorization composé par cohérence avec les autres
 * Resources, même si ses méthodes de mutation restent inertes en
 * pratique (aucune page/action de mutation n'existe pour les
 * invoquer) — même raisonnement que WarehouseStockResource.
 */
class InvoiceResource extends Resource
{
    use HasRoleBasedAuthorization;
    use BlocksChauffeurReadAccess;

    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Facture';

    protected static ?string $pluralModelLabel = 'Factures';

    protected static ?string $navigationLabel = 'Factures';

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
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
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
