<?php

namespace App\Filament\Resources\FiscalSettings\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * `activity` n'est jamais modifiable ici : c'est une clé système fixe
 * (cf. FiscalSetting::ACTIVITY_VTC, référencée en dur dans
 * VtcRide::resolveTaxRate()) — créer une nouvelle activité via un
 * formulaire générique ne ferait rien de plus tant qu'aucun code ne la
 * consomme. La seule action utile pour un admin est de choisir QUEL
 * TaxRate s'applique à une activité déjà existante.
 *
 * tax_rate_id reste nullable et effaçable : repasser à "aucun taux" est
 * un choix volontaire (régime non configuré), pas une erreur.
 */
class FiscalSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('activity')
                    ->label('Activité')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('label')
                    ->label('Libellé'),

                Select::make('tax_rate_id')
                    ->label('Taux appliqué')
                    ->relationship(
                        'taxRate',
                        'label',
                        fn (Builder $query) => $query->where('is_active', true),
                    )
                    ->searchable()
                    ->preload()
                    ->helperText(
                        'Aucun taux sélectionné = régime non configuré : aucune TVA ne sera '
                        .'calculée pour les nouvelles courses tant que ce champ reste vide. '
                        .'Choisissez un taux de type "Exonéré" pour représenter une franchise '
                        .'en base — jamais un taux à 0 %.'
                    ),
            ]);
    }
}
