<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use App\Models\Club;

class ClubSeeder extends Seeder
{
    public function run(): void
    {
        $clubs = [
            ['name' => 'Paris Saint-Germain'],
            ['name' => 'Real Madrid'],
            ['name' => 'FC Barcelona'],
            ['name' => 'Manchester United'],
            ['name' => 'Manchester City'],
            ['name' => 'Liverpool'],
            ['name' => 'Arsenal'],
            ['name' => 'Chelsea'],
            ['name' => 'Bayern Munich'],
            ['name' => 'Borussia Dortmund'],
            ['name' => 'Juventus'],
            ['name' => 'Inter Milan'],
            ['name' => 'AC Milan'],
            ['name' => 'Atlético Madrid'],
            ['name' => 'Olympique de Marseille'],
            ['name' => 'Olympique Lyonnais'],
            ['name' => 'AS Monaco'],
            ['name' => 'Ajax'],
            ['name' => 'Benfica'],
            ['name' => 'FC Porto'],
            ['name' => 'Raja Casablanca'],
            ['name' => 'Wydad Casablanca'],
            ['name' => 'Al Ahly'],
            ['name' => 'Al Hilal'],
            ['name' => 'Al Nassr'],
            ['name' => 'Inter Miami'],
            ['name' => 'LA Galaxy'],
        ];

        foreach ($clubs as $club) {
            Club::firstOrCreate(
                ['slug' => Str::slug($club['name'])],
                ['name' => $club['name']]
            );
        }
    }
}