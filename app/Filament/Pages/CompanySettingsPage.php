<?php

namespace App\Filament\Pages;

use App\Models\CompanySettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Étape T23 — page de réglages "singleton" (une seule ligne de config,
 * cf. CompanySettings::current()) : identité légale de Magarrou et
 * régime fiscal, jamais supposés ni codés en dur (D5). Première Page
 * Filament custom de ce projet — aucune Resource create/edit
 * classique n'a de sens ici (un seul enregistrement, jamais une
 * liste).
 *
 * Accès strictement réservé à l'admin (comme UserResource) : ces
 * réglages déterminent les mentions légales de TOUTES les factures
 * futures, jamais accessible à un manager/viewer.
 */
class CompanySettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Paramètres de facturation';

    protected static ?string $title = 'Paramètres de facturation';

    protected string $view = 'filament.pages.company-settings-page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('admin') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(CompanySettings::current()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Identité légale')
                    ->columns(2)
                    ->schema([
                        TextInput::make('legal_name')->label('Raison sociale')->required(),
                        TextInput::make('legal_form')->label('Forme juridique')->placeholder('SASU'),
                        TextInput::make('share_capital')->label('Capital social (€)')->numeric(),
                        TextInput::make('siren')->label('SIREN')->required(),
                        TextInput::make('siret')->label('SIRET'),
                        TextInput::make('rcs_city')->label('Ville RCS'),
                        TextInput::make('address')->label('Adresse')->required()->columnSpanFull(),
                        TextInput::make('postal_code')->label('Code postal')->required(),
                        TextInput::make('city')->label('Ville')->required(),
                        TextInput::make('country')->label('Pays')->required()->default('France'),
                    ]),

                Section::make('Régime fiscal')
                    ->description(
                        "Aucune valeur n'est présumée : ces informations doivent être confirmées avec votre expert-comptable avant toute émission de facture réelle."
                    )
                    ->columns(2)
                    ->schema([
                        Select::make('vat_regime')
                            ->label('Régime de TVA')
                            ->options([
                                CompanySettings::VAT_REGIME_STANDARD => 'Régime réel (TVA classique)',
                                CompanySettings::VAT_REGIME_FRANCHISE => 'Franchise en base de TVA',
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('vat_number')
                            ->label('N° TVA intracommunautaire')
                            ->helperText('Requis si régime réel.'),
                        Textarea::make('vat_exemption_mention')
                            ->label("Mention d'exonération à afficher")
                            ->helperText('Requis si franchise en base. Texte exact fourni par votre comptable, jamais présumé par le système.')
                            ->columnSpanFull(),
                        Select::make('vat_payment_option')
                            ->label('Option TVA sur les débits')
                            ->options(['debits' => 'TVA sur les débits', 'encaissements' => 'TVA sur les encaissements'])
                            ->helperText("Concerne les prestations de services uniquement — sans effet tant que le catalogue ne porte que des ventes de biens.")
                            ->columnSpanFull(),
                    ]),

                Section::make('Conditions commerciales')
                    ->columns(2)
                    ->schema([
                        TextInput::make('default_payment_terms_days')->label('Délai de paiement (jours)')->numeric(),
                        TextInput::make('recovery_indemnity_amount')->label('Indemnité forfaitaire de recouvrement (€)')->numeric()->required(),
                        Textarea::make('payment_terms_text')->label('Conditions de paiement (texte affiché)')->columnSpanFull(),
                        Textarea::make('discount_terms_text')->label("Conditions d'escompte (texte affiché)")->columnSpanFull(),
                        Textarea::make('late_penalty_text')->label('Pénalités de retard (texte affiché)')->columnSpanFull(),
                    ]),

                Section::make('Coordonnées')
                    ->columns(2)
                    ->schema([
                        TextInput::make('iban')->label('IBAN'),
                        TextInput::make('bic')->label('BIC'),
                        TextInput::make('email')->label('Email')->email(),
                        TextInput::make('phone')->label('Téléphone')->tel(),
                        TextInput::make('invoice_number_prefix')->label('Préfixe des numéros de facture')->required()->default('FA'),
                    ]),
            ]);
    }

    public function save(): void
    {
        CompanySettings::current()->update($this->form->getState());

        Notification::make()
            ->title('Paramètres de facturation enregistrés')
            ->success()
            ->send();
    }
}
