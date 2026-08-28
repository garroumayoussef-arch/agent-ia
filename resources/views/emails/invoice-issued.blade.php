<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px;">
    <p>Bonjour {{ $invoice->customer_name }},</p>

    <p>
        Veuillez trouver ci-joint votre facture n° <strong>{{ $invoice->number }}</strong>,
        émise le {{ $invoice->issued_at->format('d/m/Y') }}, d'un montant de
        {{ $invoice->total_ttc !== null ? number_format((float) $invoice->total_ttc, 2, ',', ' ') : '—' }} € TTC.
    </p>

    <p>
        {{ $invoice->originLabel() }}
    </p>

    <p>Cordialement,<br>{{ $invoice->seller_legal_name }}</p>
</body>
</html>
