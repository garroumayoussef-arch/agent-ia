<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Competition;

class CompetitionSeeder extends Seeder
{
    public function run(): void
    {
        $competitions = [
            ['name' => 'FIFA World Cup'],
            ['name' => 'UEFA Champions League'],
            ['name' => 'UEFA Europa League'],
            ['name' => 'UEFA Conference League'],
            ['name' => 'Premier League'],
            ['name' => 'La Liga'],
            ['name' => 'Ligue 1'],
            ['name' => 'Serie A'],
            ['name' => 'Bundesliga'],
            ['name' => 'Eredivisie'],
            ['name' => 'Primeira Liga'],
            ['name' => 'Botola Pro'],
            ['name' => 'Africa Cup of Nations'],
            ['name' => 'Copa América'],
            ['name' => 'Euro'],
            ['name' => 'NBA'],
            ['name' => 'NFL'],
            ['name' => 'NHL'],
            ['name' => 'Formula 1'],
            ['name' => 'MotoGP'],
        ];

        foreach ($competitions as $competition) {
            Competition::firstOrCreate(
                ['slug' => Str::slug($competition['name'])],
                ['name' => $competition['name']]
            );
        }
    }
}