<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\CompetitionDay;
use App\Models\Pilot;
use App\Models\PilotTurnpoint;
use App\Models\Setting;

class ScoringService
{
    /**
     * Haversine distance in km between two lat/lng points.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Evaluate a math formula string with variable substitution.
     * Supports: +, -, *, /, parentheses, decimal numbers.
     * No eval() — uses a recursive descent parser.
     */
    public static function evaluate(string $formula, array $variables): float
    {
        // Replace variables (longest first to avoid partial matches)
        $keys = array_keys($variables);
        usort($keys, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($keys as $key) {
            $formula = str_replace($key, (string) $variables[$key], $formula);
        }

        // Tokenize
        $tokens = [];
        $i = 0;
        $len = strlen($formula);
        while ($i < $len) {
            $ch = $formula[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }
            if ($ch === '(' || $ch === ')' || $ch === '+' || $ch === '*' || $ch === '/') {
                $tokens[] = $ch;
                $i++;
                continue;
            }
            // Handle minus: could be unary negative or subtraction
            if ($ch === '-') {
                $tokens[] = '-';
                $i++;
                continue;
            }
            // Number
            if (ctype_digit($ch) || $ch === '.') {
                $num = '';
                while ($i < $len && (ctype_digit($formula[$i]) || $formula[$i] === '.')) {
                    $num .= $formula[$i];
                    $i++;
                }
                $tokens[] = (float) $num;
                continue;
            }
            throw new \RuntimeException("Unexpected character '{$ch}' in formula");
        }

        $pos = 0;
        $result = self::parseExpression($tokens, $pos);

        if ($pos < count($tokens)) {
            throw new \RuntimeException('Unexpected token after expression end');
        }

        return $result;
    }

    private static function parseExpression(array &$tokens, int &$pos): float
    {
        $left = self::parseTerm($tokens, $pos);
        while ($pos < count($tokens) && is_string($tokens[$pos]) && ($tokens[$pos] === '+' || $tokens[$pos] === '-')) {
            $op = $tokens[$pos];
            $pos++;
            $right = self::parseTerm($tokens, $pos);
            $left = $op === '+' ? $left + $right : $left - $right;
        }
        return $left;
    }

    private static function parseTerm(array &$tokens, int &$pos): float
    {
        $left = self::parseUnary($tokens, $pos);
        while ($pos < count($tokens) && is_string($tokens[$pos]) && ($tokens[$pos] === '*' || $tokens[$pos] === '/')) {
            $op = $tokens[$pos];
            $pos++;
            $right = self::parseUnary($tokens, $pos);
            if ($op === '/') {
                if ($right == 0) {
                    throw new \RuntimeException('Division by zero');
                }
                $left = $left / $right;
            } else {
                $left = $left * $right;
            }
        }
        return $left;
    }

    private static function parseUnary(array &$tokens, int &$pos): float
    {
        if ($pos < count($tokens) && $tokens[$pos] === '-') {
            $pos++;
            return -self::parsePrimary($tokens, $pos);
        }
        if ($pos < count($tokens) && $tokens[$pos] === '+') {
            $pos++;
        }
        return self::parsePrimary($tokens, $pos);
    }

    private static function parsePrimary(array &$tokens, int &$pos): float
    {
        if ($pos >= count($tokens)) {
            throw new \RuntimeException('Unexpected end of formula');
        }

        $token = $tokens[$pos];

        if ($token === '(') {
            $pos++;
            $value = self::parseExpression($tokens, $pos);
            if ($pos >= count($tokens) || $tokens[$pos] !== ')') {
                throw new \RuntimeException('Missing closing parenthesis');
            }
            $pos++;
            return $value;
        }

        if (is_float($token) || is_int($token)) {
            $pos++;
            return (float) $token;
        }

        throw new \RuntimeException("Unexpected token: {$token}");
    }

    /**
     * Compute the total score for a pilot on a given day using the scoring formula.
     * Returns the sum of formula evaluations for each validated turnpoint.
     */
    public static function computePilotDayScore(Pilot $pilot, Competition $comp, CompetitionDay $day): int
    {
        $formula = Setting::get('scoring_formula', $comp->id)
            ?? '(DISTANCE_TURNPOINT * BASE) / COEF_PLANEUR';
        $base = (float) (Setting::get('scoring_base', $comp->id) ?? 100);

        // Get pilot's handicap from linked participant
        $participant = $pilot->participants()->where('competition_id', $comp->id)->first();
        $handicap = $participant ? (float) $participant->handicap : 1.00;

        // Get validated turnpoints for this pilot on this day
        $validatedTurnpoints = PilotTurnpoint::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->with('turnpoint')
            ->get();

        $totalScore = 0.0;

        foreach ($validatedTurnpoints as $pt) {
            $tp = $pt->turnpoint;
            if (!$tp) continue;

            $distance = self::haversineKm(
                (float) $comp->start_lat,
                (float) $comp->start_lng,
                (float) $tp->lat,
                (float) $tp->lng
            );

            $variables = [
                'DISTANCE_TURNPOINT' => $distance,
                'BASE' => $base,
                'COEF_PLANEUR' => $handicap,
                'HANDICAP' => $handicap,
            ];

            try {
                $totalScore += self::evaluate($formula, $variables);
            } catch (\RuntimeException $e) {
                // Skip turnpoint on formula error
                continue;
            }
        }

        return (int) round($totalScore);
    }
}
