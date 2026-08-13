<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Supplier;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            ['name' => 'Alibaba'],
            ['name' => '1688'],
            ['name' => 'AliExpress'],
            ['name' => 'Temu'],
            ['name' => 'Amazon'],
            ['name' => 'Decathlon'],
            ['name' => 'Nike'],
            ['name' => 'Adidas'],
            ['name' => 'Puma'],
            ['name' => 'New Balance'],
            ['name' => 'Joma'],
            ['name' => 'Umbro'],
            ['name' => 'Kappa'],
            ['name' => 'Hummel'],
            ['name' => 'Under Armour'],
            ['name' => 'Autre'],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::firstOrCreate([
                'name' => $supplier['name'],
            ]);
        }
    }
}