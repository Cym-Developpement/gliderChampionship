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
        $filteredContent = $this->normaliseRecords($rawContent);

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

        $alreadyValidated = $this->turnpointsValidatedOnOtherDays($pilot, $day, $comp->id);

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
                // Journée où la balise a déjà été validée, si le règlement
                // interdit de la compter deux fois.
                'already_day'    => $alreadyValidated[$tp->id] ?? null,
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
            'total_points'                => 'nullable|integer|min:0|max:1000000',
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

        $vache = $request->boolean('vache');
        $bonus = (int) $request->input('bonus_points', 0);

        // Le total affiché à l'écran fait foi : c'est la décision de
        // l'organisateur, qui a vu la trace et arbitré balise par balise. Le
        // recalculer ici rouvrirait la porte aux divergences — un pilote
        // déclaré non volant, par exemple, verrait sa base ramenée à zéro
        // alors que l'écran annonçait le contraire.
        $submitted = $request->input('total_points');

        if ($submitted !== null && $submitted !== '') {
            $score  = max(0, (int) $submitted);
            $detail = 'total validé à l\'écran';
        } else {
            // Repli si le champ n'a pas été transmis (JavaScript inactif).
            $base   = ScoringService::computePilotDayScore($pilot, $comp, $day);
            $score  = (int) round($vache ? $base / 2 : $base) + $bonus;
            $detail = $base . ' pts';
        }

        if ($vache) {
            $detail .= ' ÷ 2 (vaché)';
        }
        if ($bonus > 0) {
            $detail .= ' + ' . $bonus . ' bonus';
        }

        // Consolide : d'anciennes clôtures ont pu laisser des doublons.
        $existing = PilotScore::consolidate($pilot->id, $day->id);

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
     * Balises que ce pilote a déjà validées lors d'une autre journée.
     *
     * N'a de sens que si le règlement interdit de compter deux fois la même
     * balise : sans ce réglage, chaque journée repart de zéro et une nouvelle
     * validation est parfaitement légitime.
     *
     * @return array<int, int> turnpoint_id => numéro de la journée
     */
    private function turnpointsValidatedOnOtherDays(Pilot $pilot, CompetitionDay $day, int $competitionId): array
    {
        if ((Setting::get('turnpoint_unique_validation', $competitionId) ?? '0') !== '1') {
            return [];
        }

        return PilotTurnpoint::where('pilot_id', $pilot->id)
            ->where('competition_day_id', '!=', $day->id)
            ->with('competitionDay')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->turnpoint_id => $row->competitionDay?->day_number,
            ])
            ->filter()
            ->all();
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
     * Met la trace en forme avant analyse.
     *
     * Trois corrections, toutes nécessaires pour que la bibliothèque lise le
     * fichier tel que l'enregistreur l'a écrit :
     *
     * 1. Les types d'enregistrement inconnus sont retirés, sinon les extensions
     *    propriétaires (LXWP, X...) font échouer le parsing.
     * 2. L'en-tête de date au format IGC 2016 (HFDTEDATE:290726,01) est ramené
     *    à l'ancien HFDTE290726, seul reconnu par la bibliothèque. Sans cela la
     *    trace prend la date du jour et les heures de validation sont fausses.
     * 3. Les points marqués « V » sont écartés : le récepteur n'avait pas de
     *    position 3D et écrit souvent 0°N 0°E. Un tel point est à 5 000 km du
     *    suivant, ce que le filtre anti-aberration de la bibliothèque interprète
     *    comme une vitesse impossible — il rejette alors le point suivant, puis
     *    tous les autres, qu'il continue de comparer au même point resté en
     *    place. Une trace entière peut ainsi se réduire à un seul fix.
     */
    private function normaliseRecords(string $content): string
    {
        $lines = explode("\n", $content);
        $kept  = [];

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            if ($line === '' || !in_array($line[0], self::KNOWN_RECORD_TYPES, true)) {
                continue;
            }

            if (preg_match('/^HFDTEDATE:(\d{6})/', $line, $m)) {
                $kept[] = 'HFDTE' . $m[1];
                continue;
            }

            if (preg_match('/^B\d{6}\d{7}[NS]\d{8}[EW]V/', $line)) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }
}
