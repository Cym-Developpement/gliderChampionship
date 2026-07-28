<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\CompetitionDay;
use App\Models\Pilot;
use App\Models\PilotScore;
use App\Models\PilotTurnpoint;

/**
 * Récapitulatif de journée d'un pilote : balises validées et total.
 *
 * S'appuie sur ScoringService pour la valeur des balises, afin que le courriel
 * annonce exactement ce que porte le classement.
 */
class DayRecapService
{
    /**
     * @return array{
     *   pilot: Pilot,
     *   glider: ?\App\Models\Participant,
     *   turnpoints: list<array{name: string, points: int, validated_at: ?string, source: ?string}>,
     *   total: int,
     *   validated: bool,
     *   adjusted: bool
     * }
     */
    public static function build(Pilot $pilot, Competition $comp, CompetitionDay $day): array
    {
        $config   = ScoringService::scoringConfig($comp);
        $glider   = $pilot->participantForDay($day, $comp->id);
        $handicap = $glider ? (float) $glider->handicap : 1.00;

        $rows = PilotTurnpoint::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->with('turnpoint')
            ->orderBy('validated_at')
            ->get();

        $turnpoints = [];
        $sum = 0;

        foreach ($rows as $row) {
            if (!$row->turnpoint) {
                continue;
            }

            $points = (int) round(
                ScoringService::pointsForTurnpoint($row->turnpoint, $comp, $handicap, $config)
            );
            $sum += $points;

            $turnpoints[] = [
                'name'         => $row->turnpoint->name,
                'points'       => $points,
                'validated_at' => $row->validated_at?->format('H:i:s'),
                'source'       => $row->source,
            ];
        }

        // Le score enregistré fait foi : il porte les arbitrages de la
        // validation IGC — vache, bonus — que la somme des balises ignore.
        $score = PilotScore::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->orderByDesc('measured_at')
            ->first();

        $total = $score ? (int) $score->points : $sum;

        return [
            'pilot'      => $pilot,
            'glider'     => $glider,
            'turnpoints' => $turnpoints,
            'total'      => $total,
            'validated'  => (bool) ($score?->is_validated),
            // Le total diffère de la somme des balises : bonus ou pénalité.
            'adjusted'   => $score !== null && $total !== $sum,
        ];
    }
}
