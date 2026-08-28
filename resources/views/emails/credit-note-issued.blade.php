<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px;">
    <p>Bonjour {{ $creditNote->customer_name }},</p>

    <p>
        Veuillez trouver ci-joint votre avoir n° <strong>{{ $creditNote->number }}</strong>,
        émis le {{ $creditNote->issued_at->format('d/m/Y') }}, d'un montant de
        {{ $creditNote->total_ttc !== null ? number_format((float) $creditNote->total_ttc, 2, ',', ' ') : '—' }} € TTC,
        établi sur la facture {{ $creditNote->invoice_number_reference }}.
    </p>

    <p>Cordialement,<br>{{ $creditNote->seller_legal_name }}</p>
</body>
</html>
