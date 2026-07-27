<?php

namespace Database\Seeders;

use App\Models\Competition;
use App\Models\Participant;
use App\Models\Score;
use App\Models\Pilot;
use App\Models\PilotScore;
use Illuminate\Database\Seeder;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        // Competition (créer si aucune, sinon utiliser la plus récente)
        $comp = Competition::latest('id')->first();
        if (!$comp) {
            $comp = Competition::create([
                'name' => 'Championnat Régional Planeur',
                'start_name' => 'LFCT Thouars',
                'start_lat' => 46.96217,
                'start_lng' => -0.15812,
                'start_radius_km' => 30,
            ]);
        } else {
            $comp->update([
                'name' => 'Championnat Régional Planeur',
                'start_name' => 'LFCT Thouars',
                'start_lat' => 46.96217,
                'start_lng' => -0.15812,
                'start_radius_km' => 30,
            ]);
        }

        // Participants (similaires aux anciens JSON)
        $p1 = Participant::firstOrCreate(
            ['external_id' => 'FRA-101'],
            [
                'name' => 'Alpha',
                'ogn_id' => '8bde76b8',
                'reg' => 'D-9989',
                'glider_brand' => 'Schempp-Hirth',
                'glider_model' => 'Discus 2a',
                'competition_id' => $comp->id,
            ]
        );
        $p1->update([
            'competition_id' => $comp->id,
            'glider_brand' => 'Schempp-Hirth',
            'glider_model' => 'Discus 2a',
        ]);

        $p2 = Participant::firstOrCreate(
            ['external_id' => 'FRA-202'],
            [
                'name' => 'Bravo',
                'ogn_id' => 'fc73a533',
                'reg' => 'F-CDXX',
                'glider_brand' => 'Alexander Schleicher',
                'glider_model' => 'ASW 28',
                'competition_id' => $comp->id,
            ]
        );
        $p2->update([
            'competition_id' => $comp->id,
            'glider_brand' => 'Alexander Schleicher',
            'glider_model' => 'ASW 28',
        ]);

        $p3 = Participant::firstOrCreate(
            ['external_id' => 'FRA-303'],
            [
                'name' => 'Charlie',
                'ogn_id' => 'dd2222',
                'reg' => 'F-ABCD',
                'glider_brand' => 'DG Flugzeugbau',
                'glider_model' => 'DG-300',
                'competition_id' => $comp->id,
            ]
        );
        $p3->update([
            'competition_id' => $comp->id,
            'glider_brand' => 'DG Flugzeugbau',
            'glider_model' => 'DG-300',
        ]);

        // Lier pilotes aux participants via leurs immatriculations
        // (effectué après création des pilotes plus bas)

        // Scores de démonstration
        Score::create(['participant_id' => $p1->id, 'points' => 320, 'measured_at' => now()->subMinutes(2)]);
        Score::create(['participant_id' => $p2->id, 'points' => 275, 'measured_at' => now()->subMinutes(2)]);
        Score::create(['participant_id' => $p3->id, 'points' => 410, 'measured_at' => now()->subMinutes(2)]);

        // Pilotes
        $pilot1 = Pilot::firstOrCreate(
            ['competition_id' => $comp->id, 'name' => 'Pilote A'],
            ['callsign' => 'ALPHA', 'photo_path' => 'pilots/pilot-a.svg']
        );
        $pilot1->update(['photo_path' => 'pilots/pilot-a.svg']);

        $pilot2 = Pilot::firstOrCreate(
            ['competition_id' => $comp->id, 'name' => 'Pilote B'],
            ['callsign' => 'BRAVO', 'photo_path' => 'pilots/pilot-b.svg']
        );
        $pilot2->update(['photo_path' => 'pilots/pilot-b.svg']);

        $pilot3 = Pilot::firstOrCreate(
            ['competition_id' => $comp->id, 'name' => 'Pilote C'],
            ['callsign' => 'CHARLIE', 'photo_path' => 'pilots/pilot-c.svg']
        );
        $pilot3->update(['photo_path' => 'pilots/pilot-c.svg']);
        PilotScore::create(['pilot_id' => $pilot1->id, 'points' => 150, 'measured_at' => now()->subMinutes(1)]);
        PilotScore::create(['pilot_id' => $pilot2->id, 'points' => 180, 'measured_at' => now()->subMinutes(1)]);
        PilotScore::create(['pilot_id' => $pilot3->id, 'points' => 120, 'measured_at' => now()->subMinutes(1)]);

        // Lier après que pilotes existent
        if ($p2->reg) { $pilot1->participants()->syncWithoutDetaching([$p2->id]); }
        if ($p1->reg) { $pilot2->participants()->syncWithoutDetaching([$p1->id]); }
        if ($p3->reg) { $pilot3->participants()->syncWithoutDetaching([$p3->id]); }

        // ----------------------------
        // 15 participants et pilotes supplémentaires
        // ----------------------------
        $brands = [
            ['brand' => 'Schempp-Hirth', 'models' => ['Discus 2a', 'Ventus 2', 'Duo Discus']],
            ['brand' => 'Alexander Schleicher', 'models' => ['ASW 28', 'ASG 29', 'ASK 21']],
            ['brand' => 'DG Flugzeugbau', 'models' => ['DG-300', 'DG-1000', 'LS8']],
            ['brand' => 'Glasflügel', 'models' => ['Standard Libelle', 'H-304', 'Hornet']],
            ['brand' => 'Rolladen-Schneider', 'models' => ['LS4', 'LS7', 'LS10']]
        ];

        $createdParticipantIds = [];
        for ($i = 4; $i <= 18; $i++) {
            $b = $brands[($i - 4) % count($brands)];
            $model = $b['models'][($i - 4) % count($b['models'])];
            $extId = sprintf('FRA-%03d', 100 + $i);
            $reg = sprintf('F-C%03d', 800 + $i); // immatriculation factice unique
            $name = 'P'.chr(62 + $i); // noms courts uniques

            $p = Participant::firstOrCreate(
                ['external_id' => $extId],
                [
                    'name' => $name,
                    'ogn_id' => null,
                    'reg' => $reg,
                    'glider_brand' => $b['brand'],
                    'glider_model' => $model,
                    'competition_id' => $comp->id,
                ]
            );
            $p->update([
                'competition_id' => $comp->id,
                'glider_brand' => $b['brand'],
                'glider_model' => $model,
            ]);
            $createdParticipantIds[] = $p->id;

            // Créer un pilote associé
            $pilot = Pilot::firstOrCreate(
                ['competition_id' => $comp->id, 'name' => 'Pilote '.$i],
                ['callsign' => 'P'.$i, 'photo_path' => null]
            );
            $pilot->participants()->syncWithoutDetaching([$p->id]);
            PilotScore::create(['pilot_id' => $pilot->id, 'points' => rand(100, 450), 'measured_at' => now()->subMinutes(rand(1, 5))]);
        }
    }
}
