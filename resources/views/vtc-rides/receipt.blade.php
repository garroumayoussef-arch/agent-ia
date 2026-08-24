<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu {{ $ride->reference }}</title>
    <style>
        {{-- Étape 5.9 : reçu récapitulatif, pas une facture légale —
             aucune donnée légale d'entreprise (SIRET, adresse, n° TVA)
             n'existe dans le projet, cf. le disclaimer plus bas. --}}
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #1f2937;
            max-width: 720px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            border-bottom: 2px solid #1f2937;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        header h1 { font-size: 1.1rem; margin: 0; }
        header .reference { font-size: 0.95rem; color: #4b5563; }
        h2 {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #6b7280;
            margin: 1.5rem 0 .5rem;
        }
        table { width: 100%; border-collapse: collapse; }
        table.details td { padding: .25rem 0; vertical-align: top; }
        table.details td:first-child { color: #6b7280; width: 40%; }
        table.amounts { margin-top: .5rem; }
        table.amounts td { padding: .35rem 0; }
        table.amounts td:last-child { text-align: right; }
        table.amounts tr.total td {
            border-top: 1px solid #d1d5db;
            font-weight: bold;
            padding-top: .5rem;
        }
        .legal-mention {
            margin-top: 1.5rem;
            font-size: 0.85rem;
            color: #4b5563;
            font-style: italic;
        }
        .disclaimer {
            margin-top: 2.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
            font-size: 0.75rem;
            color: #9ca3af;
        }
        .print-button {
            margin-bottom: 1.5rem;
        }
        @media print {
            .print-button { display: none; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
    <button class="print-button" onclick="window.print()">Imprimer</button>

    <header>
        <h1>{{ config('app.name') }}</h1>
        <div class="reference">Reçu {{ $ride->reference }}</div>
    </header>

    <h2>Client</h2>
    <table class="details">
        <tr><td>Nom</td><td>{{ $ride->customer?->name ?? '-' }}</td></tr>
        @if ($ride->customer?->company)
            <tr><td>Société</td><td>{{ $ride->customer->company }}</td></tr>
        @endif
        <tr><td>Adresse</td><td>{{ $ride->customer?->address ?? '-' }}</td></tr>
        <tr><td>Ville / Pays</td><td>{{ trim(($ride->customer?->city ?? '').' '.($ride->customer?->country ?? ''), ' ') ?: '-' }}</td></tr>
        <tr><td>Email</td><td>{{ $ride->customer?->email ?? '-' }}</td></tr>
        <tr><td>Téléphone</td><td>{{ $ride->customer?->phone ?? '-' }}</td></tr>
    </table>

    <h2>Course</h2>
    <table class="details">
        <tr><td>Date de prestation</td><td>{{ $ride->performed_at?->format('d/m/Y H:i') ?? '-' }}</td></tr>
        <tr><td>Date de confirmation</td><td>{{ $ride->confirmed_at?->format('d/m/Y H:i') ?? '-' }}</td></tr>
        <tr><td>Chauffeur</td><td>{{ $ride->driver?->name ?? '-' }}</td></tr>
        <tr><td>Véhicule</td><td>{{ $ride->vehicle?->plate_number ?? '-' }}</td></tr>
        <tr><td>Plateforme</td><td>{{ $ride->platform ?? '-' }}</td></tr>
    </table>

    <h2>Montants</h2>
    <table class="amounts">
        <tr>
            <td>Prix HT saisi</td>
            <td>{{ \App\Filament\Resources\VtcRides\Tables\VtcRidesTable::formatMoneyOrDash($ride->price_ht) }}</td>
        </tr>
        <tr>
            <td>Remise</td>
            <td>{{ \App\Filament\Resources\VtcRides\Tables\VtcRidesTable::formatMoneyOrDash($ride->discount_amount) }}</td>
        </tr>
        <tr>
            <td>Montant HT (après remise)</td>
            <td>{{ \App\Filament\Resources\VtcRides\Tables\VtcRidesTable::formatMoneyOrDash($ride->total_ht) }}</td>
        </tr>
        <tr>
            <td>TVA{{ $ride->tax_rate !== null ? " ({$ride->tax_rate} %)" : '' }}</td>
            <td>{{ \App\Filament\Resources\VtcRides\Tables\VtcRidesTable::formatTaxAmount($ride) }}</td>
        </tr>
        <tr class="total">
            <td>Total TTC</td>
            <td>{{ \App\Filament\Resources\VtcRides\Tables\VtcRidesTable::formatMoneyOrDash($ride->total_ttc) }}</td>
        </tr>
    </table>

    @if ($ride->legal_mention)
        <div class="legal-mention">{{ $ride->legal_mention }}</div>
    @endif

    <div class="disclaimer">
        Ce document est un reçu récapitulatif de course, fourni à titre
        indicatif — il ne constitue pas une facture au sens légal.
    </div>
</body>
</html>
