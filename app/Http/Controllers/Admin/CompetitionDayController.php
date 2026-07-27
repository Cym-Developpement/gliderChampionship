<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\CompetitionDay;
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

        // Freeze scores: compute final score via ScoringService formula
        $pilots = Pilot::where('competition_id', $competition->id)->get();

        foreach ($pilots as $pilot) {
            $finalPoints = ScoringService::computePilotDayScore($pilot, $competition, $day);

            PilotScore::create([
                'pilot_id' => $pilot->id,
                'competition_day_id' => $day->id,
                'points' => $finalPoints,
                'measured_at' => now(),
            ]);
        }

        $day->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        return redirect()->route('admin.competition.edit')->with('success', "Jour {$day->day_number} clôturé. Scores figés.");
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

            $participant = $pilot->participants->first();
            $handicap = $participant ? (float) $participant->handicap : 1.00;

            $scores[] = [
                'pilot'        => $pilot,
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
