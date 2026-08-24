<?php

namespace Database\Seeders;

use App\Models\TaxRate;
use Illuminate\Database\Seeder;

class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        $taxRates = [
            [
                'label' => 'Taux normal — marchandises',
                'type' => TaxRate::TYPE_PERCENTAGE,
                'rate' => 20.00,
                'legal_mention' => null,
                'is_default_purchase' => true,
                'is_default_sale' => true,
                'is_active' => true,
            ],
            [
                'label' => 'Transport de voyageurs (VTC)',
                'type' => TaxRate::TYPE_PERCENTAGE,
                'rate' => 10.00,
                'legal_mention' => null,
                'is_default_purchase' => false,
                'is_default_sale' => false,
                'is_active' => true,
            ],
            [
                'label' => 'Franchise en base de TVA',
                'type' => TaxRate::TYPE_EXEMPT,
                'rate' => null,
                'legal_mention' => 'TVA non applicable, article 293 B du CGI',
                'is_default_purchase' => false,
                'is_default_sale' => false,
                'is_active' => true,
            ],
        ];

        foreach ($taxRates as $taxRate) {
            TaxRate::firstOrCreate(
                ['label' => $taxRate['label']],
                $taxRate,
            );
        }
    }
}
