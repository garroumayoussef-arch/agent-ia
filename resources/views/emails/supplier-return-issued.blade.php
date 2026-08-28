<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 14px;">
    <p>Bonjour,</p>

    <p>
        Nous vous informons d'un retour physique de marchandise, enregistré le
        {{ $return->returned_at->format('d/m/Y') }}, en lien avec le bon de commande
        n° <strong>{{ $purchaseOrder->reference }}</strong>. Vous trouverez le bon de
        retour correspondant en pièce jointe.
    </p>

    <p>Cordialement.</p>
</body>
</html>
