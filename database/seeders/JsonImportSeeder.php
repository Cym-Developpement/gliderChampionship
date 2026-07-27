<?php

namespace Database\Seeders;

use App\Models\Competition;
use App\Models\Participant;
use Illuminate\Database\Seeder;

class JsonImportSeeder extends Seeder
{
    public function run(): void
    {
        $base = base_path('public');

        // Import competition.json
        $compPath = $base . '/competition.json';
        if (file_exists($compPath) && Competition::count() === 0) {
            $data = json_decode(file_get_contents($compPath), true) ?: [];
            if (!empty($data)) {
                $start = $data['start'] ?? [];
                Competition::create([
                    'name' => $data['name'] ?? 'Competition',
                    'start_name' => $start['name'] ?? null,
                    'start_lat' => $start['lat'] ?? null,
                    'start_lng' => $start['lng'] ?? null,
                    'start_radius_km' => $start['radiusKm'] ?? null,
                ]);
            }
        }

        // Import participants.json
        $partPath = $base . '/participants.json';
        if (file_exists($partPath) && Participant::count() === 0) {
            $data = json_decode(file_get_contents($partPath), true) ?: [];
            foreach (($data['participants'] ?? []) as $p) {
                Participant::create([
                    'external_id' => $p['id'] ?? null,
                    'name' => $p['name'] ?? 'Participant',
                    'ogn_id' => $p['ognId'] ?? null,
                    'reg' => $p['reg'] ?? null,
                ]);
            }
        }
    }
}


