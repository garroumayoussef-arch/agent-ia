<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px;">
    <p>Bonjour {{ $creditNote->customer_name }},</p>

    <p>
        Nous vous confirmons la bonne prise en compte de votre retour de marchandise,
        enregistré le {{ $return->returned_at->format('d/m/Y') }}, en lien avec l'avoir
        n° <strong>{{ $creditNote->number }}</strong>. Vous trouverez le bon de retour
        correspondant en pièce jointe.
    </p>

    <p>Cordialement,<br>{{ $creditNote->seller_legal_name }}</p>
</body>
</html>
