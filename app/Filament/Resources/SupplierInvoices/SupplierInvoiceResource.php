<?php

namespace App\Filament\Resources\SupplierInvoices;

use App\Filament\Concerns\BlocksChauffeurReadAccess;
use App\Filament\Concerns\HasRoleBasedAuthorization;
use App\Filament\Resources\SupplierInvoices\Pages\CreateSupplierInvoice;
use App\Filament\Resources\SupplierInvoices\Pages\ListSupplierInvoices;
use App\Filament\Resources\SupplierInvoices\Pages\ViewSupplierInvoice;
use App\Filament\Resources\SupplierInvoices\Schemas\SupplierInvoiceForm;
use App\Filament\Resources\SupplierInvoices\Schemas\SupplierInvoiceInfolist;
use App\Filament\Resources\SupplierInvoices\Tables\SupplierInvoicesTable;
use App\Models\SupplierInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Étape T28 — enregistrement des factures fournisseurs reçues.
 * Contrairement à Invoice/CreditNote (T23/T24), une vraie page de
 * création existe (CreateSupplierInvoice) : il n'y a aucune source à
 * partir de laquelle générer ces données (un utilisateur transcrit
 * manuellement un document reçu) — mais, comme Invoice, aucune page
 * d'édition n'existe : la facture devient immuable dès son
 * enregistrement (cf. SupplierInvoice::booted(), décision 4).
 *
 * HasRoleBasedAuthorization (admin/manager en écriture, dont
 * canCreate() — protection standard Filament, aucune garde
 * supplémentaire façon T25/T26 nécessaire pour CreateAction/ViewAction,
 * déjà auto-protégées nativement) + BlocksChauffeurReadAccess (même
 * principe que PurchaseOrderResource : les achats ne concernent pas un
 * compte chauffeur).
 *
 * Périmètre V1 (décisions validées) : un seul PurchaseOrder par
 * facture, aucun suivi de paiement, aucune pièce jointe, aucune
 * numérotation automatique (le numéro est celui du fournisseur).
 */
class SupplierInvoiceResource extends Resource
{
    use HasRoleBasedAuthorization;
    use BlocksChauffeurReadAccess;

    protected static ?string $model = SupplierInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $recordTitleAttribute = 'supplier_invoice_number';

    protected static ?string $modelLabel = 'Facture fournisseur';

    protected static ?string $pluralModelLabel = 'Factures fournisseurs';

    protected static ?string $navigationLabel = 'Factures fournisseurs';

    public static function form(Schema $schema): Schema
    {
        return SupplierInvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupplierInvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierInvoicesTable::configure($table);
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
            'index' => ListSupplierInvoices::route('/'),
            'create' => CreateSupplierInvoice::route('/create'),
            'view' => ViewSupplierInvoice::route('/{record}'),
        ];
    }
}
