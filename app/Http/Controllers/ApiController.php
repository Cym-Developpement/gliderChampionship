<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\CompetitionDay;
use App\Models\Participant;
use App\Models\Score;
use App\Models\Pilot;
use App\Models\PilotScore;
use App\Models\PilotTurnpoint;
use App\Models\Setting;
use App\Models\Turnpoint;
use App\Services\ScoringService;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    public function competition()
    {
        $comp = Competition::latest('id')->first();
        if (!$comp) {
            return response()->json(null, 204);
        }

        $activeDay = $comp->activeDay();

        return response()->json([
            'name' => $comp->name,
            'status' => $comp->status,
            'start' => [
                'name' => $comp->start_name,
                'lat' => $comp->start_lat,
                'lng' => $comp->start_lng,
                'radiusKm' => $comp->start_radius_km,
            ],
            'activeDay' => $activeDay ? [
                'id' => $activeDay->id,
                'dayNumber' => $activeDay->day_number,
            ] : null,
            'devMode' => (bool) env('DEV_FAKE_POSITIONS', false),
        ]);
    }

    public function participants()
    {
        $comp = Competition::latest('id')->first();
        $query = Participant::query();
        if ($comp) {
            $query->where('competition_id', $comp->id);
        }
        $list = $query->select('id', 'external_id as externalId', 'name', 'reg', 'ogn_id as ognId', 'glider_brand as gliderBrand', 'glider_model as gliderModel')->get();
        return response()->json(['participants' => $list]);
    }

    public function live()
    {
        // Dernier score par participant
        $latestByPid = Score::select('participant_id')
            ->selectRaw('MAX(measured_at) as max_at')
            ->groupBy('participant_id');

        $rows = Score::joinSub($latestByPid, 'm', function ($join) {
                $join->on('scores.participant_id', '=', 'm.participant_id')
                     ->on('scores.measured_at', '=', 'm.max_at');
            })
            ->get(['scores.participant_id', 'scores.points', 'scores.measured_at']);

        $points = [];
        foreach ($rows as $row) {
            $pid = Participant::find($row->participant_id);
            if ($pid) {
                $points[$pid->external_id] = (int)$row->points;
            }
        }
        return response()->json([
            'updatedAt' => now()->toISOString(),
            'points' => (object)$points,
        ]);
    }

    public function pilots()
    {
        $comp = Competition::latest('id')->first();
        $query = Pilot::query();
        if ($comp) {
            $query->where('competition_id', $comp->id);
        }
        // Charger la relation participants; on utilisera le premier participant lié dans la même compétition
        $pilots = $query->with(['participants' => function ($q) use ($comp) {
            if ($comp) {
                $q->where('competition_id', $comp->id);
            }
        }])->get();

        // Le planeur peut changer d'une journée à l'autre : c'est celui du jour
        // actif qui doit être renvoyé, sinon la carte rattacherait les positions
        // OGN à la mauvaise immatriculation.
        $activeDay = $comp?->activeDay();

        // Un pilote sans planeur ne vole pas : il n'a ni position OGN ni score
        // possible, et n'encombre donc pas la vue live. Le repli sur
        // l'association globale s'applique d'abord — n'est écarté que celui qui
        // n'a aucun planeur, ni pour le jour ni pour la compétition.
        $list = $pilots->map(function ($p) use ($activeDay, $comp) {
            /** @var \App\Models\Participant|null $linked */
            $linked = $p->participantForDay($activeDay, $comp?->id);

            if (!$linked) {
                return null;
            }

            return [
                'id' => $p->id,
                'name' => $p->name,
                'callsign' => $p->callsign,
                'photoUrl' => $p->photo_url,
                'reg' => $linked->reg,
                'gliderBrand' => $linked->glider_brand,
                'gliderModel' => $linked->glider_model,
            ];
        })->filter()->values();

        return response()->json(['pilots' => $list]);
    }

    public function turnpoints()
    {
        $comp = Competition::latest('id')->first();
        if (!$comp) {
            return response()->json(['turnpoints' => []]);
        }
        $turnpoints = Turnpoint::where('competition_id', $comp->id)
            ->orderBy('order')
            ->get(['id', 'name', 'lat', 'lng', 'radius_m', 'points', 'order']);
        return response()->json(['turnpoints' => $turnpoints]);
    }

    public function settings()
    {
        $comp = Competition::latest('id')->first();
        $compId = $comp?->id;

        $keys = ['proximity_far_m', 'proximity_near_m', 'map_view_mode', 'map_radius_km', 'map_zoom'];
        $result = [];
        foreach ($keys as $key) {
            $value = Setting::get($key, $compId);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return response()->json(['settings' => $result]);
    }

    public function livePilots()
    {
        $comp = Competition::latest('id')->first();
        $activeDay = $comp ? $comp->activeDay() : null;

        if ($activeDay) {
            // Compute scores via ScoringService for each pilot
            $pilots = Pilot::where('competition_id', $comp->id)->get();

            $points = [];
            foreach ($pilots as $pilot) {
                $score = ScoringService::computePilotDayScore($pilot, $comp, $activeDay);
                if ($score > 0) {
                    $points[(string) $pilot->id] = $score;
                }
            }

            return response()->json([
                'updatedAt' => now()->toISOString(),
                'points'    => (object) $points,
                'validated' => false, // FLARM scores are always provisional
            ]);
        }

        // No active day: default behavior (raw scores)
        $latestByPid = PilotScore::select('pilot_id')
            ->selectRaw('MAX(measured_at) as max_at')
            ->groupBy('pilot_id');

        $rows = PilotScore::joinSub($latestByPid, 'm', function ($join) {
                $join->on('pilot_scores.pilot_id', '=', 'm.pilot_id')
                     ->on('pilot_scores.measured_at', '=', 'm.max_at');
            })
            ->get(['pilot_scores.pilot_id', 'pilot_scores.points', 'pilot_scores.measured_at']);

        $points = [];
        foreach ($rows as $row) {
            $points[(string)$row->pilot_id] = (int)$row->points;
        }
        return response()->json([
            'updatedAt' => now()->toISOString(),
            'points' => (object)$points,
        ]);
    }

    public function validateTurnpoint(Request $request)
    {
        $request->validate([
            'pilot_id' => 'required|integer|exists:pilots,id',
            'turnpoint_id' => 'required|integer|exists:turnpoints,id',
        ]);

        $comp = Competition::latest('id')->first();
        if (!$comp) {
            return response()->json(['error' => 'No competition'], 404);
        }

        $activeDay = $comp->activeDay();
        if (!$activeDay) {
            return response()->json(['error' => 'No active day'], 422);
        }

        // If unique validation is enabled, check across all days
        $uniqueValidation = Setting::get('turnpoint_unique_validation', $comp->id) ?? '0';
        if ($uniqueValidation === '1') {
            $alreadyValidated = PilotTurnpoint::where('pilot_id', $request->input('pilot_id'))
                ->where('turnpoint_id', $request->input('turnpoint_id'))
                ->exists();
            if ($alreadyValidated) {
                return response()->json(['ok' => false, 'already_validated' => true]);
            }
        }

        // Insert or ignore (unique constraint prevents duplicates)
        PilotTurnpoint::insertOrIgnore([
            'pilot_id' => $request->input('pilot_id'),
            'turnpoint_id' => $request->input('turnpoint_id'),
            'competition_day_id' => $activeDay->id,
            'validated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Compute and return updated score
        $pilot = Pilot::findOrFail($request->input('pilot_id'));
        $score = ScoringService::computePilotDayScore($pilot, $comp, $activeDay);

        return response()->json([
            'ok' => true,
            'score' => $score,
        ]);
    }

    public function validatedTurnpoints()
    {
        $comp = Competition::latest('id')->first();
        if (!$comp) {
            return response()->json(['validated' => []]);
        }

        $uniqueValidation = Setting::get('turnpoint_unique_validation', $comp->id) ?? '0';

        $query = PilotTurnpoint::query()
            ->whereHas('turnpoint', fn($q) => $q->where('competition_id', $comp->id));

        if ($uniqueValidation !== '1') {
            $activeDay = $comp->activeDay();
            if (!$activeDay) {
                return response()->json(['validated' => []]);
            }
            $query->where('competition_day_id', $activeDay->id);
        }

        $validated = $query->get(['pilot_id', 'turnpoint_id'])
            ->map(fn($row) => [$row->pilot_id, $row->turnpoint_id]);

        return response()->json(['validated' => $validated]);
    }

    public function generalRanking()
    {
        $comp = Competition::latest('id')->first();
        if (!$comp) {
            return response()->json(['pilots' => []]);
        }

        $pilots = Pilot::where('competition_id', $comp->id)->with('participants')->get();
        $closedDays = $comp->days()->where('status', 'closed')->orderBy('day_number')->get();
        $activeDay = $comp->activeDay();

        // Get live scores for active day via ScoringService
        $liveScores = [];
        if ($activeDay) {
            foreach ($pilots as $pilot) {
                $score = ScoringService::computePilotDayScore($pilot, $comp, $activeDay);
                if ($score > 0) {
                    $liveScores[$pilot->id] = $score;
                }
            }
        }

        $result = [];
        foreach ($pilots as $pilot) {
            $dayScores = [];
            $dayValidated = [];
            $total = 0;

            // Frozen scores from closed days
            foreach ($closedDays as $day) {
                $frozenScore = PilotScore::where('pilot_id', $pilot->id)
                    ->where('competition_day_id', $day->id)
                    ->orderByDesc('measured_at')
                    ->first();
                $pts = $frozenScore ? (int) $frozenScore->points : 0;
                $dayScores[$day->day_number] = $pts;
                $dayValidated[$day->day_number] = $frozenScore ? (bool) $frozenScore->is_validated : false;
                $total += $pts;
            }

            // Active day live score — always provisional (FLARM-based)
            if ($activeDay) {
                $livePts = $liveScores[$pilot->id] ?? 0;
                $dayScores[$activeDay->day_number] = $livePts;
                $dayValidated[$activeDay->day_number] = false;
                $total += $livePts;
            }

            $result[] = [
                'id'          => $pilot->id,
                'name'        => $pilot->name,
                'callsign'    => $pilot->callsign,
                'photoUrl'    => $pilot->photo_url,
                'dayScores'   => (object) $dayScores,
                'dayValidated'=> (object) $dayValidated,
                'total'       => $total,
            ];
        }

        // Sort by total descending
        usort($result, fn($a, $b) => $b['total'] - $a['total']);

        return response()->json(['pilots' => $result]);
    }
}
