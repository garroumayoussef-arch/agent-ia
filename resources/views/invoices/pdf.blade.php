<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Facture {{ $invoice->number }}</title>
    <style>
        {{-- Étape T23 — aucune mention fiscale n'est écrite en dur ici :
             tout ce qui touche à la TVA/mentions légales est lu depuis
             les colonnes *_snapshot de $invoice, jamais présumé (D5). --}}
        body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 11px; }
        header { display: flex; justify-content: space-between; border-bottom: 2px solid #1f2937; padding-bottom: 10px; margin-bottom: 16px; }
        h1 { font-size: 16px; margin: 0 0 4px; }
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
        table.totals tr.grand-total td { font-weight: bold; border-top: 2px solid #1f2937; }
        .mentions { font-size: 9.5px; color: #4b5563; border-top: 1px solid #d1d5db; padding-top: 8px; margin-top: 16px; }
        .mentions p { margin: 2px 0; }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>{{ $invoice->seller_legal_name }}</h1>
            @if($invoice->seller_legal_form)
                <div>{{ $invoice->seller_legal_form }}@if($invoice->seller_share_capital) — Capital social : {{ number_format($invoice->seller_share_capital, 2, ',', ' ') }} €@endif</div>
            @endif
            <div>{{ $invoice->seller_address }}</div>
            <div>{{ $invoice->seller_postal_code }} {{ $invoice->seller_city }}, {{ $invoice->seller_country }}</div>
            <div>SIREN : {{ $invoice->seller_siren }}@if($invoice->seller_siret) — SIRET : {{ $invoice->seller_siret }}@endif</div>
            @if($invoice->seller_rcs_city)
                <div>RCS {{ $invoice->seller_rcs_city }}</div>
            @endif
            @if($invoice->seller_vat_number)
                <div>N° TVA intracommunautaire : {{ $invoice->seller_vat_number }}</div>
            @endif
        </div>
        <div style="text-align: right;">
            <h1>Facture {{ $invoice->number }}</h1>
            <div>Date d'émission : {{ $invoice->issued_at->format('d/m/Y') }}</div>
            <div>Date de vente/prestation : {{ $invoice->sale_completed_at->format('d/m/Y') }}</div>
            <div class="muted">Commande : {{ $invoice->sales_order_reference }}</div>
        </div>
    </header>

    <table class="parties">
        <tr>
            <td>
                <div class="box">
                    <strong>Client</strong><br>
                    {{ $invoice->customer_name }}<br>
                    @if($invoice->customer_company)
                        {{ $invoice->customer_company }}<br>
                    @endif
                    @if($invoice->customer_address)
                        {{ $invoice->customer_address }}<br>
                    @endif
                    @if($invoice->customer_postal_code || $invoice->customer_city)
                        {{ $invoice->customer_postal_code }} {{ $invoice->customer_city }}<br>
                    @endif
                    {{ $invoice->customer_country }}
                    @if($invoice->customer_type === 'business')
                        <br>SIREN : {{ $invoice->customer_siren }}
                        @if($invoice->customer_vat_number)
                            <br>N° TVA : {{ $invoice->customer_vat_number }}
                        @endif
                    @endif
                </div>
            </td>
            <td>
                @if($invoice->delivery_address_snapshot && $invoice->delivery_address_snapshot !== $invoice->customer_address)
                    <div class="box">
                        <strong>Adresse de livraison</strong><br>
                        {{ $invoice->delivery_address_snapshot }}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Désignation</th>
                <th class="num">Qté</th>
                <th class="num">PU HT</th>
                <th class="num">Taux TVA</th>
                <th class="num">Total TTC</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lines as $line)
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
        <tr><td>Total HT</td><td class="num">{{ number_format($invoice->total_ht, 2, ',', ' ') }} €</td></tr>
        @if($invoice->discount_amount > 0)
            <tr><td>Remise</td><td class="num">-{{ number_format($invoice->discount_amount, 2, ',', ' ') }} €</td></tr>
        @endif
        <tr><td>Total TVA</td><td class="num">{{ $invoice->tax_amount !== null ? number_format($invoice->tax_amount, 2, ',', ' ').' €' : '-' }}</td></tr>
        <tr class="grand-total"><td>Total TTC</td><td class="num">{{ $invoice->total_ttc !== null ? number_format($invoice->total_ttc, 2, ',', ' ').' €' : '-' }}</td></tr>
    </table>

    {{-- D5 — chaque mention n'apparaît QUE si elle a été explicitement
         configurée dans CompanySettings au moment de l'émission : aucune
         valeur par défaut, aucune mention fiscale déduite ici. --}}
    <div class="mentions">
        @if($invoice->vat_exemption_mention_snapshot)
            <p>{{ $invoice->vat_exemption_mention_snapshot }}</p>
        @endif
        @if($invoice->payment_terms_snapshot)
            <p>Conditions de paiement : {{ $invoice->payment_terms_snapshot }}</p>
        @endif
        @if($invoice->discount_terms_snapshot)
            <p>{{ $invoice->discount_terms_snapshot }}</p>
        @endif
        @if($invoice->late_penalty_snapshot)
            <p>{{ $invoice->late_penalty_snapshot }}</p>
        @endif
        <p>Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : {{ number_format($invoice->recovery_indemnity_amount_snapshot, 2, ',', ' ') }} €.</p>
    </div>
</body>
</html>
