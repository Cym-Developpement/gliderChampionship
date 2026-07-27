<?php

namespace Database\Seeders;

use App\Models\Competition;
use App\Models\CompetitionDay;
use App\Models\Participant;
use App\Models\Pilot;
use App\Models\PilotScore;
use App\Models\PilotTurnpoint;
use App\Models\Setting;
use App\Models\Turnpoint;
use App\Services\ScoringService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LiveDemoSeeder extends Seeder
{
    public function run(): void
    {
        $comp = Competition::latest('id')->first();
        if (!$comp) {
            $this->command->error('Aucune compétition trouvée.');
            return;
        }

        // Pilotes réalistes avec immatriculations valides
        $pilotData = [
            ['name' => 'Jean-Pierre Moreau',   'callsign' => 'JPM',  'reg' => 'F-CJPM', 'brand' => 'Schempp-Hirth',       'model' => 'Ventus 2cx',   'handicap' => 1.00, 'ogn_id' => 'A1B2C3'],
            ['name' => 'Sophie Delacroix',     'callsign' => 'SDX',  'reg' => 'F-CSDX', 'brand' => 'Alexander Schleicher', 'model' => 'ASG 29',       'handicap' => 0.98, 'ogn_id' => 'D4E5F6'],
            ['name' => 'Marc Lefebvre',        'callsign' => 'MLF',  'reg' => 'F-CMLF', 'brand' => 'DG Flugzeugbau',      'model' => 'DG-1000S',     'handicap' => 1.05, 'ogn_id' => '112233'],
            ['name' => 'Claire Fontaine',      'callsign' => 'CLF',  'reg' => 'F-CCLF', 'brand' => 'Rolladen-Schneider',  'model' => 'LS8-18',       'handicap' => 0.96, 'ogn_id' => '445566'],
            ['name' => 'Antoine Rousseau',     'callsign' => 'ARX',  'reg' => 'F-CARX', 'brand' => 'Schempp-Hirth',       'model' => 'Discus 2c',    'handicap' => 1.02, 'ogn_id' => '778899'],
            ['name' => 'Isabelle Martin',      'callsign' => 'ISM',  'reg' => 'F-CISM', 'brand' => 'Glasflügel',          'model' => 'H-304',        'handicap' => 1.08, 'ogn_id' => 'AABBCC'],
            ['name' => 'Philippe Dubois',      'callsign' => 'PHD',  'reg' => 'F-CPHD', 'brand' => 'Alexander Schleicher', 'model' => 'ASW 28-18e',  'handicap' => 0.99, 'ogn_id' => 'DDEEFF'],
            ['name' => 'Nathalie Girard',      'callsign' => 'NGD',  'reg' => 'F-CNGD', 'brand' => 'Schempp-Hirth',       'model' => 'Duo Discus XL','handicap' => 1.10, 'ogn_id' => '001122'],
            ['name' => 'Christophe Leroy',     'callsign' => 'CLR',  'reg' => 'F-CCLR', 'brand' => 'DG Flugzeugbau',      'model' => 'LS6-c',        'handicap' => 1.03, 'ogn_id' => '334455'],
            ['name' => 'Virginie Petit',       'callsign' => 'VPT',  'reg' => 'F-CVPT', 'brand' => 'Rolladen-Schneider',  'model' => 'LS4-b',        'handicap' => 1.07, 'ogn_id' => '667788'],
            ['name' => 'François Bernard',     'callsign' => 'FBD',  'reg' => 'F-CFBD', 'brand' => 'Alexander Schleicher', 'model' => 'ASK 21',      'handicap' => 1.15, 'ogn_id' => '99AABB'],
            ['name' => 'Émilie Dupont',        'callsign' => 'EDP',  'reg' => 'F-CEDP', 'brand' => 'Schempp-Hirth',       'model' => 'Discus CS',    'handicap' => 1.12, 'ogn_id' => 'CCDDEE'],
            ['name' => 'Sébastien Marchand',   'callsign' => 'SMD',  'reg' => 'F-CSMD', 'brand' => 'DG Flugzeugbau',      'model' => 'DG-300',       'handicap' => 1.18, 'ogn_id' => 'FF0011'],
            ['name' => 'Camille Renard',       'callsign' => 'CRD',  'reg' => 'F-CCRD', 'brand' => 'Glasflügel',          'model' => 'Standard Libelle', 'handicap' => 1.20, 'ogn_id' => '223344'],
            ['name' => 'Thomas Gauthier',      'callsign' => 'TGT',  'reg' => 'F-CTGT', 'brand' => 'Rolladen-Schneider',  'model' => 'LS7-WL',       'handicap' => 1.01, 'ogn_id' => '556677'],
            ['name' => 'Aurélie Chevalier',    'callsign' => 'ACH',  'reg' => 'F-CACH', 'brand' => 'Alexander Schleicher', 'model' => 'ASG 32',      'handicap' => 0.97, 'ogn_id' => '889900'],
            ['name' => 'Nicolas Lambert',      'callsign' => 'NLB',  'reg' => 'F-CNLB', 'brand' => 'Schempp-Hirth',       'model' => 'Ventus 3',     'handicap' => 0.95, 'ogn_id' => 'AABBDD'],
            ['name' => 'Marie-Claude Simon',   'callsign' => 'MCS',  'reg' => 'F-CMCS', 'brand' => 'DG Flugzeugbau',      'model' => 'DG-808C',      'handicap' => 1.06, 'ogn_id' => 'EEFF00'],
        ];

        // Nettoyage ciblé sur la compétition
        $pilotIds = Pilot::where('competition_id', $comp->id)->pluck('id');
        PilotTurnpoint::whereIn('pilot_id', $pilotIds)->delete();
        PilotScore::whereIn('pilot_id', $pilotIds)->delete();

        $participantIds = Participant::where('competition_id', $comp->id)->pluck('id');
        DB::table('participant_pilot')->whereIn('participant_id', $participantIds)->delete();
        DB::table('participant_pilot')->whereIn('pilot_id', $pilotIds)->delete();

        Participant::where('competition_id', $comp->id)->delete();
        Pilot::where('competition_id', $comp->id)->delete();

        // Vider le cache des fausses positions
        \Illuminate\Support\Facades\Cache::store('file')->forget('dev_fake_positions');

        // Récupérer la journée active et les journées fermées
        $activeDay = $comp->activeDay();
        $closedDays = $comp->days()->where('status', 'closed')->orderBy('day_number')->get();

        // Turnpoints disponibles
        $turnpoints = Turnpoint::where('competition_id', $comp->id)->orderBy('order')->get();

        // Créer participants + pilotes
        $created = [];
        foreach ($pilotData as $i => $data) {
            $participant = Participant::create([
                'competition_id' => $comp->id,
                'external_id'    => sprintf('FRA-%03d', 100 + $i + 1),
                'name'           => $data['name'],
                'reg'            => $data['reg'],
                'ogn_id'         => $data['ogn_id'],
                'glider_brand'   => $data['brand'],
                'glider_model'   => $data['model'],
                'handicap'       => $data['handicap'],
            ]);

            $pilot = Pilot::create([
                'competition_id' => $comp->id,
                'name'           => $data['name'],
                'callsign'       => $data['callsign'],
                'photo_path'     => null,
            ]);

            $pilot->participants()->attach($participant->id);
            $created[] = ['pilot' => $pilot, 'participant' => $participant, 'handicap' => $data['handicap']];
        }

        // Scores journées fermées (Jour 1 déjà clos)
        foreach ($closedDays as $day) {
            foreach ($created as $idx => $entry) {
                $pts = max(0, 350 - ($idx * 18) + rand(-30, 30));
                PilotScore::create([
                    'pilot_id'           => $entry['pilot']->id,
                    'competition_day_id' => $day->id,
                    'points'             => $pts,
                    'measured_at'        => now()->subDay(),
                ]);
            }
        }

        // Validation de turnpoints pour la journée active
        // Chaque pilote valide un nombre différent de turnpoints (0 à 5)
        // pour simuler différentes étapes de progression
        if ($activeDay && $turnpoints->isNotEmpty()) {
            // Distribution: les premiers pilotes ont validé plus de turnpoints
            $validationCounts = [5, 5, 4, 4, 4, 3, 3, 3, 2, 2, 2, 2, 1, 1, 1, 0, 0, 0];
            foreach ($created as $idx => $entry) {
                $count = $validationCounts[$idx] ?? 0;
                $tpsToValidate = $turnpoints->take($count);
                foreach ($tpsToValidate as $tp) {
                    PilotTurnpoint::create([
                        'pilot_id'           => $entry['pilot']->id,
                        'turnpoint_id'       => $tp->id,
                        'competition_day_id' => $activeDay->id,
                        'validated_at'       => now()->subMinutes(rand(5, 120)),
                    ]);
                }
            }
        }

        $this->command->info('Données live démo créées : '.count($created).' pilotes/participants.');
    }
}
