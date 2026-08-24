<?php

namespace Database\Seeders;

use App\Models\FiscalSetting;
use Illuminate\Database\Seeder;

class FiscalSettingSeeder extends Seeder
{
    /**
     * Crée la ligne de régime fiscal pour l'activité VTC, volontairement
     * non configurée (tax_rate_id = null) : on ne présume ni régime réel
     * (10 %) ni franchise en base tant qu'un administrateur ne l'a pas
     * explicitement choisi.
     */
    public function run(): void
    {
        FiscalSetting::firstOrCreate(
            ['activity' => FiscalSetting::ACTIVITY_VTC],
            [
                'label' => 'Transport de voyageurs (VTC)',
                'tax_rate_id' => null,
            ],
        );
    }
}
