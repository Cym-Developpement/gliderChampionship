<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CompetitionDayController;
use App\Http\Controllers\Admin\IgcValidationController;
use App\Models\Competition;
use App\Models\CompetitionDay;
use App\Models\DayAssignment;
use App\Models\Participant;
use App\Models\Pilot;
use App\Models\PilotScore;
use App\Models\PilotTurnpoint;
use App\Models\Setting;
use App\Models\Turnpoint;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Calcul du score d'une journée : la formule y est confrontée aux vraies
 * données — balises validées, handicap du planeur du jour, forfait.
 */
class PilotDayScoreTest extends TestCase
{
    use RefreshDatabase;

    /** Barème réel de la compétition. */
    private const BAREME = 'POINTS_BALISE > 0 ? POINTS_BALISE '
        . ': (DISTANCE_TURNPOINT <= 50 ? 10 : (DISTANCE_TURNPOINT <= 90 ? 20 : 30))';

    private const START_LAT = 46.962418;

    private const START_LNG = -0.157499;

    private Competition $competition;

    private CompetitionDay $day;

    private Pilot $pilot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->competition = Competition::create([
            'name'            => 'Wassmer Cup',
            'start_name'      => 'LFCT Thouars',
            'start_lat'       => self::START_LAT,
            'start_lng'       => self::START_LNG,
            'start_radius_km' => 30,
            'status'          => 'active',
        ]);

        $this->day = CompetitionDay::create([
            'competition_id' => $this->competition->id,
            'day_number'     => 1,
            'date'           => '2026-07-27',
            'status'         => 'active',
        ]);

        $this->pilot = Pilot::create([
            'competition_id' => $this->competition->id,
            'name'           => 'Aubin Ternisien',
        ]);

        $this->setFormula(self::BAREME);
    }

    private function setFormula(string $formula): void
    {
        Setting::updateOrCreate(
            ['key' => 'scoring_formula', 'competition_id' => $this->competition->id],
            ['value' => $formula]
        );
    }

    /** Crée une balise à la distance voulue, plein nord du point de départ. */
    private function turnpointAt(float $km, int $points = 0): Turnpoint
    {
        return Turnpoint::create([
            'competition_id' => $this->competition->id,
            'name'           => 'Balise ' . $km . ' km',
            'lat'            => self::START_LAT + $km / 111.32,
            'lng'            => self::START_LNG,
            'radius_m'       => 2500,
            'points'         => $points,
            'order'          => 1,
        ]);
    }

    private function validate(Turnpoint $turnpoint): void
    {
        PilotTurnpoint::create([
            'pilot_id'           => $this->pilot->id,
            'turnpoint_id'       => $turnpoint->id,
            'competition_day_id' => $this->day->id,
            'validated_at'       => now(),
        ]);
    }

    private function glider(float $handicap): Participant
    {
        $participant = Participant::create([
            'external_id'    => 'FCDSP' . $handicap,
            'name'           => 'Aubin Ternisien',
            'reg'            => 'F-CDSP',
            'competition_id' => $this->competition->id,
            'handicap'       => $handicap,
        ]);
        $participant->pilots()->attach($this->pilot->id);

        return $participant;
    }

    private function score(): int
    {
        return ScoringService::computePilotDayScore(
            $this->pilot->fresh(),
            $this->competition,
            $this->day
        );
    }

    // ─── Barème ──────────────────────────────────────────────────────────────

    public function test_les_trois_paliers_se_cumulent(): void
    {
        $this->validate($this->turnpointAt(30));    // 10 pts
        $this->validate($this->turnpointAt(70));    // 20 pts
        $this->validate($this->turnpointAt(120));   // 30 pts

        $this->assertSame(60, $this->score());
    }

    public function test_balise_bonus_prime_sur_la_distance(): void
    {
        $this->validate($this->turnpointAt(30, points: 100));   // bonus, malgré 30 km

        $this->assertSame(100, $this->score());
    }

    public function test_balise_non_validee_ne_rapporte_rien(): void
    {
        $this->turnpointAt(120);   // créée mais jamais validée

        $this->assertSame(0, $this->score());
    }

    public function test_balise_validee_un_autre_jour_est_ignoree(): void
    {
        $autreJour = CompetitionDay::create([
            'competition_id' => $this->competition->id,
            'day_number'     => 2,
            'date'           => '2026-07-28',
            'status'         => 'pending',
        ]);

        $balise = $this->turnpointAt(120);
        PilotTurnpoint::create([
            'pilot_id'           => $this->pilot->id,
            'turnpoint_id'       => $balise->id,
            'competition_day_id' => $autreJour->id,
            'validated_at'       => now(),
        ]);

        $this->assertSame(0, $this->score());
    }

    // ─── Handicap ────────────────────────────────────────────────────────────

    public function test_handicap_du_planeur_applique(): void
    {
        $this->setFormula('POINTS_BALISE / COEF_PLANEUR');
        $this->glider(1.25);
        $this->validate($this->turnpointAt(30, points: 100));

        $this->assertSame(80, $this->score());   // 100 / 1.25
    }

    public function test_handicap_du_planeur_du_jour_prime(): void
    {
        $this->setFormula('POINTS_BALISE / COEF_PLANEUR');
        $this->glider(1.25);                       // planeur habituel
        $duJour = Participant::create([
            'external_id'    => 'FCHTI',
            'name'           => 'Duo Discus',
            'reg'            => 'F-CHTI',
            'competition_id' => $this->competition->id,
            'handicap'       => 2.0,
        ]);
        DayAssignment::create([
            'competition_day_id' => $this->day->id,
            'pilot_id'           => $this->pilot->id,
            'participant_id'     => $duJour->id,
        ]);

        $this->validate($this->turnpointAt(30, points: 100));

        $this->assertSame(50, $this->score());   // 100 / 2.0, et non / 1.25
    }

    // ─── Forfait ─────────────────────────────────────────────────────────────

    public function test_pilote_declare_non_volant_ne_marque_rien(): void
    {
        $this->validate($this->turnpointAt(120));
        DayAssignment::create([
            'competition_day_id' => $this->day->id,
            'pilot_id'           => $this->pilot->id,
            'participant_id'     => null,
        ]);

        $this->assertSame(0, $this->score());
    }

    public function test_absence_daffectation_ne_vaut_pas_forfait(): void
    {
        $this->validate($this->turnpointAt(120));

        $this->assertSame(30, $this->score());
    }

    // ─── Cohérence avec l'écran de validation IGC ────────────────────────────

    /**
     * Le total du jour doit être exactement la somme des valeurs affichées
     * balise par balise. Sommer les flottants puis arrondir une seule fois
     * donnerait parfois un point d'écart avec l'écran.
     */
    public function test_le_total_egale_la_somme_des_valeurs_affichees(): void
    {
        $this->setFormula('DISTANCE_TURNPOINT * 1.7');   // valeurs non entières
        $config = ScoringService::scoringConfig($this->competition);

        $attendu = 0;
        foreach ([12.5, 37.3, 88.9] as $km) {
            $balise = $this->turnpointAt($km);
            $this->validate($balise);
            $attendu += (int) round(
                ScoringService::pointsForTurnpoint($balise, $this->competition, 1.0, $config)
            );
        }

        $this->assertSame($attendu, $this->score());
    }

    // ─── Clôture de la journée ───────────────────────────────────────────────

    /**
     * Un score validé par IGC porte les ajustements de l'épreuve — vache,
     * bonus — que le recalcul de la clôture ne saurait reproduire.
     */
    public function test_la_cloture_preserve_un_score_valide(): void
    {
        $this->validate($this->turnpointAt(120));   // vaudrait 30 pts au recalcul

        PilotScore::create([
            'pilot_id'           => $this->pilot->id,
            'competition_day_id' => $this->day->id,
            'points'             => 65,             // 30 ÷ 2 + 50 de bonus
            'is_validated'       => true,
            'measured_at'        => now()->subMinute(),
        ]);

        (new CompetitionDayController())->close($this->day);

        $scores = PilotScore::where('pilot_id', $this->pilot->id)
            ->where('competition_day_id', $this->day->id)
            ->get();

        $this->assertCount(1, $scores, 'La clôture ne doit pas empiler une seconde ligne');
        $this->assertSame(65, (int) $scores->first()->points);
    }

    public function test_la_cloture_recalcule_un_score_non_valide(): void
    {
        $this->validate($this->turnpointAt(120));

        PilotScore::create([
            'pilot_id'           => $this->pilot->id,
            'competition_day_id' => $this->day->id,
            'points'             => 0,
            'is_validated'       => false,
            'measured_at'        => now()->subMinute(),
        ]);

        (new CompetitionDayController())->close($this->day);

        $this->assertSame(30, (int) PilotScore::where('pilot_id', $this->pilot->id)
            ->where('competition_day_id', $this->day->id)
            ->orderByDesc('measured_at')
            ->value('points'));
    }

    // ─── Validation IGC ──────────────────────────────────────────────────────

    /**
     * La validation IGC enregistre le total arbitré à l'écran, sans recalcul.
     * Le recalculer rouvrirait les divergences : ici le pilote est déclaré non
     * volant, ce qui ramènerait la base à zéro alors que l'organisateur a bien
     * vu et validé ses balises sur la trace.
     */
    public function test_la_validation_igc_enregistre_le_total_affiche(): void
    {
        $balise = $this->turnpointAt(120);
        DayAssignment::create([
            'competition_day_id' => $this->day->id,
            'pilot_id'           => $this->pilot->id,
            'participant_id'     => null,       // « ne vole pas », base à 0
        ]);

        $request = Request::create('/x', 'POST', [
            'igc_turnpoints' => [$balise->id => ['include' => '1', 'distance_m' => 400]],
            'bonus_points'   => '20',
            'total_points'   => '80',
        ]);
        app()->instance('request', $request);

        (new IgcValidationController())->save($request, $this->day, $this->pilot);

        $this->assertSame(80, (int) PilotScore::where('pilot_id', $this->pilot->id)
            ->where('competition_day_id', $this->day->id)
            ->value('points'));
    }
    /**
     * D'anciennes clôtures répétées ont laissé plusieurs lignes de score. Les
     * lecteurs prenaient la plus récente, updateScores la première venue : la
     * validation cochée s'appliquait à une ligne invisible et semblait se
     * défaire d'elle-même à l'affichage.
     */
    public function test_la_validation_survit_a_des_scores_en_double(): void
    {
        foreach ([[100, '-2 hours'], [200, 'now']] as [$points, $quand]) {
            PilotScore::create([
                'pilot_id'           => $this->pilot->id,
                'competition_day_id' => $this->day->id,
                'points'             => $points,
                'is_validated'       => false,
                'measured_at'        => new \DateTime($quand),
            ]);
        }

        $controller = new CompetitionDayController();
        $request = Request::create('/x', 'POST', [
            'scores' => [['pilot_id' => $this->pilot->id, 'points' => '200', 'is_validated' => '1']],
        ]);
        app()->instance('request', $request);

        $controller->updateScores($request, $this->day);

        $scores = PilotScore::where('pilot_id', $this->pilot->id)
            ->where('competition_day_id', $this->day->id)
            ->get();

        $this->assertCount(1, $scores, 'Les doublons doivent être résorbés');
        $this->assertTrue((bool) $scores->first()->is_validated);
    }
    // ─── Robustesse ──────────────────────────────────────────────────────────

    public function test_formule_invalide_neutralise_le_score_sans_planter(): void
    {
        $this->setFormula('DISTANCE_TURNPOINT §§');
        $this->validate($this->turnpointAt(120));

        // Une formule fautive ne doit pas interrompre le classement en pleine
        // épreuve : la balise est ignorée et le score reste calculable.
        $this->assertSame(0, $this->score());
    }

    public function test_formule_absente_utilise_la_valeur_par_defaut(): void
    {
        Setting::where('key', 'scoring_formula')->delete();
        $this->glider(1.0);
        $this->validate($this->turnpointAt(50));

        // Défaut : (DISTANCE_TURNPOINT * BASE) / COEF_PLANEUR, BASE valant 100.
        // Tolérance : la balise est placée à 50 km via une approximation du
        // degré de latitude, la distance réelle vaut 49,9 km.
        $this->assertEqualsWithDelta(5000, $this->score(), 20);
    }
}
