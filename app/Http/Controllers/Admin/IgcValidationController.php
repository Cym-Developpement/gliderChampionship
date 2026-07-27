<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompetitionDay;
use App\Models\Pilot;
use App\Models\PilotScore;
use App\Models\PilotTurnpoint;
use App\Models\Turnpoint;
use App\Services\ScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Ycdev\PhpIgcInspector\PhpIgcInspector;

class IgcValidationController extends Controller
{
    /** Record types supported by the library. Others are stripped before parsing. */
    private const KNOWN_RECORD_TYPES = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];

    public function show(CompetitionDay $day, Pilot $pilot)
    {
        $comp       = $day->competition;
        $turnpoints = Turnpoint::where('competition_id', $comp->id)->orderBy('order')->get();

        $flarmIds = PilotTurnpoint::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->pluck('turnpoint_id')
            ->flip()
            ->toArray();

        return view('admin.days.igc', compact('day', 'pilot', 'comp', 'turnpoints', 'flarmIds'));
    }

    public function process(Request $request, CompetitionDay $day, Pilot $pilot)
    {
        $request->validate([
            'igc_file' => 'required|file|max:20480',
        ]);

        $comp       = $day->competition;
        $turnpoints = Turnpoint::where('competition_id', $comp->id)->orderBy('order')->get();

        // Store IGC temporarily
        $path = $request->file('igc_file')->store('igc_tmp');
        session(['igc_tmp_' . $pilot->id . '_' . $day->id => $path]);

        // Parse via library (filter unknown record types first for robustness)
        $rawContent     = Storage::get($path);
        $filteredContent = $this->filterKnownRecords($rawContent);

        try {
            $inspector = new PhpIgcInspector($filteredContent);
            $inspector->validate();
        } catch (\Exception $e) {
            Storage::delete($path);
            return back()->withErrors(['igc_file' => 'Fichier IGC invalide : ' . $e->getMessage()]);
        }

        if (empty($inspector->getGpsPoints())) {
            Storage::delete($path);
            return back()->withErrors(['igc_file' => 'Aucun point GPS valide trouvé dans ce fichier IGC.']);
        }

        // Flight stats from the new library methods
        $fixCount    = count($inspector->getGpsPoints());
        $maxSpeedKmh = $inspector->getMaxSpeed();
        $maxAltM     = $inspector->getMaxAltitude();
        $maxGnssM    = $inspector->getMaxGnssAltitude();

        // Already validated by FLARM
        $flarmIds = PilotTurnpoint::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->pluck('turnpoint_id')
            ->flip()
            ->toArray();

        // For each turnpoint: use passedNearPoint() from the library
        $results = [];
        foreach ($turnpoints as $tp) {
            $near = $inspector->passedNearPoint(
                (float) $tp->lat,
                (float) $tp->lng,
                (float) $tp->validationRadiusM()
            );

            $distM = $near->minDistance !== null ? (int) round($near->minDistance) : null;

            // Timestamp of first entry into the radius zone (if validated)
            $validatedAt = null;
            if ($near->firstFixInside !== null && isset($near->firstFixInside->dateTime)) {
                $validatedAt = $near->firstFixInside->dateTime;
            }

            $results[] = [
                'turnpoint'      => $tp,
                'flarm'          => isset($flarmIds[$tp->id]),
                'igc'            => $near->passed,
                'distance_m'     => $distM,
                'fix_count_zone' => $near->fixCountInside,
                'validated_at'   => $validatedAt,
            ];
        }

        return view('admin.days.igc', compact(
            'day', 'pilot', 'comp', 'turnpoints', 'flarmIds',
            'results', 'fixCount', 'maxSpeedKmh', 'maxAltM', 'maxGnssM'
        ));
    }

    public function save(Request $request, CompetitionDay $day, Pilot $pilot)
    {
        $request->validate([
            'igc_turnpoints'              => 'nullable|array',
            'igc_turnpoints.*.distance_m' => 'nullable|integer|min:0',
        ]);

        $comp = $day->competition;

        foreach ($request->input('igc_turnpoints', []) as $tpId => $data) {
            if (empty($data['include'])) continue;

            $distM = isset($data['distance_m']) ? (int) $data['distance_m'] : null;

            $existing = PilotTurnpoint::where('pilot_id', $pilot->id)
                ->where('turnpoint_id', $tpId)
                ->where('competition_day_id', $day->id)
                ->first();

            if ($existing) {
                $existing->update(['igc_distance_m' => $distM]);
            } else {
                PilotTurnpoint::create([
                    'pilot_id'           => $pilot->id,
                    'turnpoint_id'       => $tpId,
                    'competition_day_id' => $day->id,
                    'validated_at'       => now(),
                    'source'             => 'igc',
                    'igc_distance_m'     => $distM,
                ]);
            }
        }

        // Recompute and freeze score, mark as validated
        $score    = ScoringService::computePilotDayScore($pilot, $comp, $day);
        $existing = PilotScore::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->first();

        if ($existing) {
            $existing->update(['points' => $score, 'is_validated' => true]);
        } else {
            PilotScore::create([
                'pilot_id'           => $pilot->id,
                'competition_day_id' => $day->id,
                'points'             => $score,
                'is_validated'       => true,
                'measured_at'        => now(),
            ]);
        }

        // Clean up temp IGC
        $sessionKey = 'igc_tmp_' . $pilot->id . '_' . $day->id;
        if ($tmpPath = session($sessionKey)) {
            Storage::delete($tmpPath);
            session()->forget($sessionKey);
        }

        return redirect()
            ->route('admin.days.scores', $day)
            ->with('success', "IGC validé — {$pilot->name} : {$score} pts. Score marqué comme validé.");
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Strip lines with unknown record types so the library doesn't throw
     * on proprietary extensions (e.g. LXWP, X...).
     */
    private function filterKnownRecords(string $content): string
    {
        $lines = explode("\n", $content);
        $kept  = array_filter($lines, function (string $line): bool {
            $line = ltrim($line, "\r");
            return $line !== '' && in_array($line[0], self::KNOWN_RECORD_TYPES, true);
        });
        return implode("\n", $kept);
    }
}
