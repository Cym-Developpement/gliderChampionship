<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompetitionDay;
use App\Models\Pilot;
use App\Models\PilotScore;
use App\Models\PilotTurnpoint;
use App\Models\Setting;
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

        // Heure de validation en vol, pour la confronter à celle de la trace.
        $flarmTimes = PilotTurnpoint::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->pluck('validated_at', 'turnpoint_id')
            ->toArray();

        return view('admin.days.igc', compact('day', 'pilot', 'comp', 'turnpoints', 'flarmIds', 'flarmTimes'));
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

        // Heure de validation en vol, pour la confronter à celle de la trace.
        $flarmTimes = PilotTurnpoint::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->pluck('validated_at', 'turnpoint_id')
            ->toArray();

        // Points tels qu'ils seront comptés : même service que le classement,
        // avec le handicap du planeur affecté ce jour-là.
        $config   = ScoringService::scoringConfig($comp);
        $handicap = (float) ($pilot->participantForDay($day, $comp->id)?->handicap ?? 1.00);

        $altitudes = $this->analyseAltitudes(
            $inspector->getGpsPoints(),
            (int) (Setting::get('vache_height_m', $comp->id) ?? 200)
        );

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
                'points'         => (int) round(
                    ScoringService::pointsForTurnpoint($tp, $comp, $handicap, $config)
                ),
                // Une balise franchie après la vache ne compte plus : le vol
                // est terminé, la suite du trace est un convoyage ou une
                // remise en l'air hors épreuve.
                'after_vache'    => $altitudes['vache_at'] !== null
                    && $validatedAt !== null
                    && strtotime((string) $validatedAt) > strtotime($altitudes['vache_at']),
            ];
        }

        return view('admin.days.igc', compact(
            'day', 'pilot', 'comp', 'turnpoints', 'flarmIds', 'flarmTimes',
            'results', 'fixCount', 'maxSpeedKmh', 'maxAltM', 'maxGnssM', 'altitudes'
        ));
    }

    public function save(Request $request, CompetitionDay $day, Pilot $pilot)
    {
        $request->validate([
            'igc_turnpoints'              => 'nullable|array',
            'igc_turnpoints.*.distance_m' => 'nullable|integer|min:0',
            'bonus_points'                => 'nullable|integer|min:0|max:100000',
            'vache'                       => 'nullable|boolean',
        ]);

        $comp = $day->competition;

        $included = [];
        foreach ($request->input('igc_turnpoints', []) as $tpId => $data) {
            if (!empty($data['include'])) {
                $included[(int) $tpId] = $data;
            }
        }

        // Le fichier IGC fait foi : les balises non retenues sont retirées de
        // la journée. Sans cela, une validation FLARM que la trace n'a pas
        // confirmée continuerait de compter, et le score enregistré
        // dépasserait le total affiché à l'écran.
        PilotTurnpoint::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->when($included !== [], fn($q) => $q->whereNotIn('turnpoint_id', array_keys($included)))
            ->delete();

        foreach ($included as $tpId => $data) {
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

        // Score des balises retenues, puis ajustements de l'épreuve : la vache
        // divise par deux, le bonus s'ajoute après — un bonus ne doit pas être
        // amputé par une pénalité qui ne porte que sur le vol lui-même.
        $base  = ScoringService::computePilotDayScore($pilot, $comp, $day);
        $vache = $request->boolean('vache');
        $bonus = (int) $request->input('bonus_points', 0);

        $score = (int) round($vache ? $base / 2 : $base) + $bonus;

        $detail = $base . ' pts';
        if ($vache) {
            $detail .= ' ÷ 2 (vaché)';
        }
        if ($bonus > 0) {
            $detail .= ' + ' . $bonus . ' bonus';
        }

        // La plus récente : d'anciennes clôtures ont pu laisser des doublons.
        $existing = PilotScore::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->orderByDesc('measured_at')
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
            ->with('success', "IGC validé — {$pilot->name} : {$score} pts ({$detail}). Score marqué comme validé.");
    }

    /**
     * Analyse des altitudes du vol.
     *
     * La hauteur est comptée au-dessus du terrain, pris comme l'altitude du
     * premier point enregistré — l'enregistreur démarre au sol. Faute de
     * modèle de terrain, c'est la seule référence disponible.
     *
     * Décollage et atterrissage sont exclus en bornant l'analyse au premier et
     * au dernier point passés au-dessus du seuil : ce qui précède est la
     * montée au treuil ou au remorqué, ce qui suit est l'approche.
     *
     * @param  list<object> $fixes
     * @return array{ground: ?int, min_height: ?int, min_at: ?string, vache_at: ?string, threshold: int}
     */
    private function analyseAltitudes(array $fixes, int $threshold): array
    {
        $empty = ['ground' => null, 'min_height' => null, 'min_at' => null, 'vache_at' => null, 'threshold' => $threshold];

        $points = [];
        foreach ($fixes as $fix) {
            // L'altitude barométrique prime ; certains enregistreurs ne
            // fournissent que la GNSS.
            $altitude = $fix->pressureAltitude ?? null;
            if (!is_numeric($altitude) || $altitude <= 0) {
                $altitude = $fix->gnssAltitude ?? null;
            }
            if (is_numeric($altitude)) {
                $points[] = ['alt' => (int) $altitude, 'at' => $fix->dateTime ?? null];
            }
        }

        if (count($points) < 3) {
            return $empty;
        }

        $ground = $points[0]['alt'];

        // Fenêtre de vol : du premier au dernier point au-dessus du seuil.
        $takeoff = null;
        $landing = null;
        foreach ($points as $i => $point) {
            if ($point['alt'] - $ground > $threshold) {
                $takeoff ??= $i;
                $landing = $i;
            }
        }

        if ($takeoff === null || $landing <= $takeoff) {
            return ['ground' => $ground] + $empty;
        }

        $minHeight = null;
        $minAt     = null;
        $vacheAt   = null;

        for ($i = $takeoff; $i <= $landing; $i++) {
            $height = $points[$i]['alt'] - $ground;

            if ($minHeight === null || $height < $minHeight) {
                $minHeight = $height;
                $minAt     = $points[$i]['at'];
            }

            // Premier passage sous le seuil en plein vol : la vache présumée.
            if ($vacheAt === null && $height <= $threshold) {
                $vacheAt = $points[$i]['at'];
            }
        }

        return [
            'ground'     => $ground,
            'min_height' => $minHeight,
            'min_at'     => $minAt,
            'vache_at'   => $vacheAt,
            'threshold'  => $threshold,
        ];
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
