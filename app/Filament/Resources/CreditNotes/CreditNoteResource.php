<?php

namespace App\Filament\Resources\CreditNotes;

use App\Filament\Concerns\BlocksChauffeurReadAccess;
use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\CreditNotes\Schemas\CreditNoteInfolist;
use App\Filament\Resources\CreditNotes\Tables\CreditNotesTable;
use App\Models\CreditNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Étape T24 — consultation des avoirs émis, strictement en LECTURE
 * SEULE : un avoir est immuable (cf. CreditNote::booted()), aucune
 * page create/edit ici — même principe que InvoiceResource (T23) et
 * WarehouseStockResource (T14). La création passe exclusivement par
 * CreditNote::generateFromInvoice(), via les actions dédiées sur
 * ViewInvoice.
 *
 * HORS scoping entrepôt (ScopesToOwnWarehouses n'est PAS composé ici,
 * comme InvoiceResource) : un avoir est rattaché à une Invoice, jamais
 * à un entrepôt. Mêmes règles d'autorisation que InvoiceResource/
 * SalesOrderResource (BlocksChauffeurReadAccess uniquement).
 */
class CreditNoteResource extends Resource
{
    use HasRoleBasedAuthorization;
    use BlocksChauffeurReadAccess;

    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Avoir';

    protected static ?string $pluralModelLabel = 'Avoirs';

    protected static ?string $navigationLabel = 'Avoirs';

    public static function table(Table $table): Table
    {
        return CreditNotesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CreditNoteInfolist::configure($schema);
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
            'index' => ListCreditNotes::route('/'),
            'view' => ViewCreditNote::route('/{record}'),
        ];
    }
}
