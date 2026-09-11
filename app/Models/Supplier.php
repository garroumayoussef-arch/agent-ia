<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * Étape T28 — factures fournisseurs enregistrées pour ce
     * fournisseur, tous bons de commande confondus. Relation additive
     * en lecture seule.
     */
    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    /**
     * Chantier Dropshipping, étape D1 — fiches de sourcing déclarées pour
     * ce fournisseur (quels produits/variantes il peut fournir, à quel
     * coût, avec quelle priorité). Relation additive en lecture seule :
     * ne crée aucune nouvelle écriture sur Supplier, capacité Core
     * réutilisable par n'importe quelle activité.
     */
    public function productSourcings(): HasMany
    {
        return $this->hasMany(SupplierProductSourcing::class);
    }
}
