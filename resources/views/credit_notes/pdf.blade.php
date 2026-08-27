<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Avoir {{ $creditNote->number }}</title>
    <style>
        {{-- Étape T24 — comme pour la facture (T23), aucune mention
             fiscale n'est écrite en dur ici : tout provient des colonnes
             *_snapshot déjà figées de $creditNote (copiées depuis la
             facture d'origine, D2). --}}
        body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 11px; }
        header { display: flex; justify-content: space-between; border-bottom: 2px solid #7f1d1d; padding-bottom: 10px; margin-bottom: 16px; }
        h1 { font-size: 16px; margin: 0 0 4px; color: #7f1d1d; }
        .muted { color: #6b7280; }
        table.parties { width: 100%; margin-bottom: 16px; }
        table.parties td { vertical-align: top; width: 50%; padding: 8px; }
        table.parties .box { border: 1px solid #d1d5db; padding: 8px; border-radius: 4px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.lines th, table.lines td { border: 1px solid #d1d5db; padding: 6px; text-align: left; }
        table.lines th { background: #f3f4f6; }
        table.lines td.num, table.lines th.num { text-align: right; }
        table.totals { width: 40%; margin-left: auto; margin-bottom: 16px; }
        table.totals td { padding: 4px 6px; }
        table.totals tr.grand-total td { font-weight: bold; border-top: 2px solid #7f1d1d; }
        .reference-box { background: #fef2f2; border: 1px solid #fecaca; padding: 8px; border-radius: 4px; margin-bottom: 16px; }
        .mentions { font-size: 9.5px; color: #4b5563; border-top: 1px solid #d1d5db; padding-top: 8px; margin-top: 16px; }
        .mentions p { margin: 2px 0; }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>{{ $creditNote->seller_legal_name }}</h1>
            @if($creditNote->seller_legal_form)
                <div>{{ $creditNote->seller_legal_form }}@if($creditNote->seller_share_capital) — Capital social : {{ number_format($creditNote->seller_share_capital, 2, ',', ' ') }} €@endif</div>
            @endif
            <div>{{ $creditNote->seller_address }}</div>
            <div>{{ $creditNote->seller_postal_code }} {{ $creditNote->seller_city }}, {{ $creditNote->seller_country }}</div>
            <div>SIREN : {{ $creditNote->seller_siren }}@if($creditNote->seller_siret) — SIRET : {{ $creditNote->seller_siret }}@endif</div>
            @if($creditNote->seller_rcs_city)
                <div>RCS {{ $creditNote->seller_rcs_city }}</div>
            @endif
            @if($creditNote->seller_vat_number)
                <div>N° TVA intracommunautaire : {{ $creditNote->seller_vat_number }}</div>
            @endif
        </div>
        <div style="text-align: right;">
            <h1>AVOIR {{ $creditNote->number }}</h1>
            <div>Date d'émission : {{ $creditNote->issued_at->format('d/m/Y') }}</div>
            <div class="muted">Portée : {{ $creditNote->scope === 'total' ? 'Avoir total' : 'Avoir partiel' }}</div>
        </div>
    </header>

    <div class="reference-box">
        <strong>Avoir établi sur la facture {{ $creditNote->invoice_number_reference }}</strong>
        du {{ $creditNote->invoice_issued_at_reference->format('d/m/Y') }}
        ({{ $creditNote->originLabel() }})<br>
        <strong>Motif :</strong> {{ $creditNote->reason }}<br>
        <strong>Mode de règlement :</strong> {{ $creditNote->settlement_type === 'refund' ? 'Remboursement' : 'Imputation sur facture future' }}
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="box">
                    <strong>Client</strong><br>
                    {{ $creditNote->customer_name }}<br>
                    @if($creditNote->customer_company)
                        {{ $creditNote->customer_company }}<br>
                    @endif
                    @if($creditNote->customer_address)
                        {{ $creditNote->customer_address }}<br>
                    @endif
                    @if($creditNote->customer_postal_code || $creditNote->customer_city)
                        {{ $creditNote->customer_postal_code }} {{ $creditNote->customer_city }}<br>
                    @endif
                    {{ $creditNote->customer_country }}
                    @if($creditNote->customer_type === 'business')
                        <br>SIREN : {{ $creditNote->customer_siren }}
                        @if($creditNote->customer_vat_number)
                            <br>N° TVA : {{ $creditNote->customer_vat_number }}
                        @endif
                    @endif
                </div>
            </td>
            <td></td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Désignation</th>
                <th class="num">Qté</th>
                <th class="num">PU HT</th>
                <th class="num">Taux TVA</th>
                <th class="num">Total TTC crédité</th>
            </tr>
        </thead>
        <tbody>
            @foreach($creditNote->lines as $line)
                <tr>
                    <td>
                        {{ $line->product_name }}
                        @if($line->variant_description)
                            <br><span class="muted">{{ $line->variant_description }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $line->quantity }}</td>
                    <td class="num">{{ number_format($line->unit_price_ht, 2, ',', ' ') }} €</td>
                    <td class="num">{{ $line->tax_rate !== null ? number_format($line->tax_rate, 2, ',', ' ').' %' : 'Exonéré' }}</td>
                    <td class="num">{{ $line->total_ttc !== null ? number_format($line->total_ttc, 2, ',', ' ').' €' : '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Total HT crédité</td><td class="num">{{ number_format($creditNote->total_ht, 2, ',', ' ') }} €</td></tr>
        <tr><td>Total TVA créditée</td><td class="num">{{ $creditNote->tax_amount !== null ? number_format($creditNote->tax_amount, 2, ',', ' ').' €' : '-' }}</td></tr>
        <tr class="grand-total"><td>Total TTC crédité</td><td class="num">{{ $creditNote->total_ttc !== null ? number_format($creditNote->total_ttc, 2, ',', ' ').' €' : '-' }}</td></tr>
    </table>

    <div class="mentions">
        <p>Ce document est un avoir venant en déduction de la facture {{ $creditNote->invoice_number_reference }} référencée ci-dessus.</p>
    </div>
</body>
</html>
