<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\CompetitionDay;
use App\Models\DayAssignment;
use App\Models\Participant;
use App\Models\Pilot;
use App\Models\PilotScore;
use App\Services\ScoringService;
use Illuminate\Http\Request;

class CompetitionDayController extends Controller
{
    public function store()
    {
        $competition = Competition::firstOrFail();

        $nextDay = ($competition->days()->max('day_number') ?? 0) + 1;

        CompetitionDay::create([
            'competition_id' => $competition->id,
            'day_number' => $nextDay,
            'date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        return redirect()->route('admin.competition.edit')->with('success', "Jour $nextDay créé.");
    }

    public function start(CompetitionDay $day)
    {
        // Verify no other day is active in this competition
        $activeDay = $day->competition->activeDay();
        if ($activeDay && $activeDay->id !== $day->id) {
            return redirect()->route('admin.competition.edit')
                ->with('error', "Le jour {$activeDay->day_number} est déjà actif. Clôturez-le d'abord.");
        }

        $day->update([
            'status' => 'active',
            'started_at' => now(),
        ]);

        return redirect()->route('admin.competition.edit')->with('success', "Jour {$day->day_number} démarré.");
    }

    public function close(CompetitionDay $day)
    {
        $competition = $day->competition;

        // Fige les scores. Deux précautions :
        //  - un score déjà validé par IGC porte les ajustements de l'épreuve
        //    (vache, bonus) que le recalcul ne saurait reproduire : on n'y
        //    touche pas ;
        //  - on met à jour la ligne existante au lieu d'en créer une nouvelle,
        //    sans quoi deux clôtures successives empileraient les scores.
        $pilots    = Pilot::where('competition_id', $competition->id)->get();
        $preserves = 0;

        foreach ($pilots as $pilot) {
            $existing = PilotScore::where('pilot_id', $pilot->id)
                ->where('competition_day_id', $day->id)
                ->orderByDesc('measured_at')
                ->first();

            if ($existing && $existing->is_validated) {
                $preserves++;
                continue;
            }

            $finalPoints = ScoringService::computePilotDayScore($pilot, $competition, $day);

            if ($existing) {
                $existing->update(['points' => $finalPoints, 'measured_at' => now()]);
            } else {
                PilotScore::create([
                    'pilot_id'           => $pilot->id,
                    'competition_day_id' => $day->id,
                    'points'             => $finalPoints,
                    'measured_at'        => now(),
                ]);
            }
        }

        $day->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $message = "Jour {$day->day_number} clôturé. Scores figés.";
        if ($preserves > 0) {
            $message .= " {$preserves} score(s) validé(s) par IGC conservé(s) tels quels.";
        }

        return redirect()->route('admin.competition.edit')->with('success', $message);
    }

    /**
     * Affectation des planeurs pour une journée.
     *
     * Chaque pilote est pré-positionné sur son planeur du jour s'il en a un,
     * sinon sur son planeur habituel : enregistrer sans rien changer fige
     * simplement la situation par défaut.
     */
    public function editAssignments(CompetitionDay $day)
    {
        $competition = $day->competition;

        $pilots = Pilot::where('competition_id', $competition->id)
            ->with('participants')
            ->orderBy('name')
            ->get();

        $gliders = Participant::where('competition_id', $competition->id)
            ->orderBy('reg')
            ->get();

        $selected = [];
        foreach ($pilots as $pilot) {
            $selected[$pilot->id] = $pilot->participantForDay($day, $competition->id)?->id;
        }

        return view('admin.days.assignments', compact('day', 'competition', 'pilots', 'gliders', 'selected'));
    }

    public function updateAssignments(Request $request, CompetitionDay $day)
    {
        $data = $request->validate([
            'assignments'   => 'array',
            'assignments.*' => 'nullable|exists:participants,id',
        ]);

        $kept = 0;
        $cleared = 0;

        foreach ($data['assignments'] ?? [] as $pilotId => $participantId) {
            // Une ligne est écrite dans tous les cas, y compris sans planeur :
            // supprimer la ligne relancerait le repli sur l'association
            // globale et la désaffectation resterait sans effet.
            DayAssignment::updateOrCreate(
                ['competition_day_id' => $day->id, 'pilot_id' => $pilotId],
                ['participant_id' => $participantId ?: null]
            );

            empty($participantId) ? $cleared++ : $kept++;
        }

        // Le handicap ayant pu changer, les scores provisoires du jour bougent.
        $message = "Affectations enregistrées : {$kept} pilote(s) avec planeur";
        $message .= $cleared > 0 ? ", {$cleared} ne volant pas ce jour-là." : '.';

        return redirect()->route('admin.days.assignments', $day)->with('success', $message);
    }

    public function editScores(CompetitionDay $day)
    {
        $competition = $day->competition;
        $pilots = Pilot::where('competition_id', $competition->id)->with('participants')->get();

        $scores = [];
        foreach ($pilots as $pilot) {
            $frozenScore = PilotScore::where('pilot_id', $pilot->id)
                ->where('competition_day_id', $day->id)
                ->orderByDesc('measured_at')
                ->first();

            // Get raw score (without day assignment)
            $rawScore = PilotScore::where('pilot_id', $pilot->id)
                ->whereNull('competition_day_id')
                ->orderByDesc('measured_at')
                ->first();

            // Planeur du jour : c'est son handicap qui a servi au calcul.
            $participant = $pilot->participantForDay($day, $competition->id);
            $handicap = $participant ? (float) $participant->handicap : 1.00;

            $scores[] = [
                'pilot'        => $pilot,
                'glider'       => $participant,
                'raw_points'   => $rawScore ? (int) $rawScore->points : 0,
                'final_points' => $frozenScore ? (int) $frozenScore->points : 0,
                'handicap'     => $handicap,
                'is_validated' => $frozenScore ? (bool) $frozenScore->is_validated : false,
            ];
        }

        return view('admin.days.scores', compact('day', 'scores'));
    }

    public function updateScores(Request $request, CompetitionDay $day)
    {
        $data = $request->validate([
            'scores'                  => 'required|array',
            'scores.*.pilot_id'       => 'required|exists:pilots,id',
            'scores.*.points'         => 'required|integer|min:0',
        ]);

        foreach ($data['scores'] as $i => $entry) {
            $isValidated = isset($request->input('scores')[$i]['is_validated']);

            $existing = PilotScore::where('pilot_id', $entry['pilot_id'])
                ->where('competition_day_id', $day->id)
                ->first();

            if ($existing) {
                $existing->update([
                    'points'       => $entry['points'],
                    'is_validated' => $isValidated,
                ]);
            } else {
                PilotScore::create([
                    'pilot_id'           => $entry['pilot_id'],
                    'competition_day_id' => $day->id,
                    'points'             => $entry['points'],
                    'is_validated'       => $isValidated,
                    'measured_at'        => now(),
                ]);
            }
        }

        return redirect()->route('admin.days.scores', $day)->with('success', 'Scores mis à jour.');
    }
}
