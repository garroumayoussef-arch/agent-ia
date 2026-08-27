<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bon de retour — Avoir {{ $creditNote->number }}</title>
    <style>
        {{-- Chantier "bon de retour" — contrairement à credit_notes/pdf.blade.php,
             les informations entreprise ($company) sont lues en direct depuis
             CompanySettings::current() (décision 2, validée), jamais figées sur
             le retour lui-même : un bon de retour n'est pas une pièce fiscale. --}}
        body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 11px; }
        header { display: flex; justify-content: space-between; border-bottom: 2px solid #1d4ed8; padding-bottom: 10px; margin-bottom: 16px; }
        h1 { font-size: 16px; margin: 0 0 4px; color: #1d4ed8; }
        .muted { color: #6b7280; }
        table.parties { width: 100%; margin-bottom: 16px; }
        table.parties td { vertical-align: top; width: 50%; padding: 8px; }
        table.parties .box { border: 1px solid #d1d5db; padding: 8px; border-radius: 4px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.lines th, table.lines td { border: 1px solid #d1d5db; padding: 6px; text-align: left; }
        table.lines th { background: #f3f4f6; }
        table.lines td.num, table.lines th.num { text-align: right; }
        .reference-box { background: #eff6ff; border: 1px solid #bfdbfe; padding: 8px; border-radius: 4px; margin-bottom: 16px; }
        .mentions { font-size: 9.5px; color: #4b5563; border-top: 1px solid #d1d5db; padding-top: 8px; margin-top: 16px; }
        .mentions p { margin: 2px 0; }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>{{ $company->legal_name ?? '-' }}</h1>
            @if($company->legal_form)
                <div>{{ $company->legal_form }}</div>
            @endif
            <div>{{ $company->address }}</div>
            <div>{{ $company->postal_code }} {{ $company->city }}, {{ $company->country }}</div>
            @if($company->siren)
                <div>SIREN : {{ $company->siren }}@if($company->siret) — SIRET : {{ $company->siret }}@endif</div>
            @endif
        </div>
        <div style="text-align: right;">
            <h1>BON DE RETOUR</h1>
            <div>Retour n° {{ $return->id }}</div>
            <div class="muted">Date du retour : {{ $return->returned_at->format('d/m/Y') }}</div>
        </div>
    </header>

    <div class="reference-box">
        <strong>Retour physique lié à l'avoir {{ $creditNote->number }}</strong>
        du {{ $creditNote->issued_at->format('d/m/Y') }}
        (facture {{ $creditNote->invoice_number_reference }})
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="box">
                    <strong>Client</strong><br>
                    {{ $creditNote->customer_company ?: $creditNote->customer_name }}<br>
                    @if($creditNote->customer_company){{ $creditNote->customer_name }}<br>@endif
                    {{ $creditNote->customer_address }}<br>
                    {{ $creditNote->customer_postal_code }} {{ $creditNote->customer_city }}, {{ $creditNote->customer_country }}
                </div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Variante</th>
                <th class="num">Quantité retournée</th>
                <th>État</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $return->product?->nom ?? '-' }}</td>
                <td>
                    @if($return->productVariant)
                        {{ implode(' / ', array_filter([$return->productVariant->size, $return->productVariant->color])) ?: ($return->productVariant->sku ?? '-') }}
                    @else
                        -
                    @endif
                </td>
                <td class="num">{{ $return->quantity }}</td>
                <td>{{ $return->condition === 'vendable' ? 'Vendable' : 'Défectueux' }}</td>
            </tr>
        </tbody>
    </table>

    <div class="mentions">
        @if($return->reference)
            <p><strong>Référence :</strong> {{ $return->reference }}</p>
        @endif
        @if($return->notes)
            <p><strong>Notes :</strong> {{ $return->notes }}</p>
        @endif
        <p><strong>Enregistré par :</strong> {{ $return->user?->name ?? '-' }}</p>
    </div>
</body>
</html>
