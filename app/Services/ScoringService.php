<?php

namespace App\Services;

use App\Models\Competition;
use App\Models\CompetitionDay;
use App\Models\Pilot;
use App\Models\PilotTurnpoint;
use App\Models\Setting;
use App\Models\Turnpoint;

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
     * Évalue une formule après substitution des variables.
     *
     * Opérateurs : + - * / ( ), comparaisons < <= > >= == != et condition ternaire
     * « cond ? a : b », qui permet d'exprimer un barème par paliers. Une
     * comparaison vaut 1 ou 0, et toute valeur non nulle est vraie.
     *
     * Pas de eval() : analyse par descente récursive.
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
            if ($ch === '(' || $ch === ')' || $ch === '+' || $ch === '*' || $ch === '/'
                || $ch === '?' || $ch === ':') {
                $tokens[] = $ch;
                $i++;
                continue;
            }
            // Comparaisons, à deux caractères d'abord pour ne pas couper <= en <
            if ($ch === '<' || $ch === '>' || $ch === '=' || $ch === '!') {
                $two = substr($formula, $i, 2);
                if (in_array($two, ['<=', '>=', '==', '!='], true)) {
                    $tokens[] = $two;
                    $i += 2;
                    continue;
                }
                if ($ch === '<' || $ch === '>') {
                    $tokens[] = $ch;
                    $i++;
                    continue;
                }
                throw new \RuntimeException("Opérateur incomplet « {$ch} » — utilisez == ou !=");
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
        $result = self::parseTernary($tokens, $pos);

        if ($pos < count($tokens)) {
            throw new \RuntimeException('Unexpected token after expression end');
        }

        return $result;
    }

    /** cond ? valeurSiVrai : valeurSinon — associatif à droite, donc chaînable. */
    private static function parseTernary(array &$tokens, int &$pos): float
    {
        $condition = self::parseComparison($tokens, $pos);

        if ($pos >= count($tokens) || $tokens[$pos] !== '?') {
            return $condition;
        }
        $pos++;

        $ifTrue = self::parseTernary($tokens, $pos);

        if ($pos >= count($tokens) || $tokens[$pos] !== ':') {
            throw new \RuntimeException('« : » manquant dans la condition ternaire');
        }
        $pos++;

        $ifFalse = self::parseTernary($tokens, $pos);

        // Toute valeur non nulle est vraie, à la tolérance des flottants près.
        return abs($condition) > 1e-9 ? $ifTrue : $ifFalse;
    }

    /** Une comparaison rend 1 ou 0, ce qui la rend combinable avec l'arithmétique. */
    private static function parseComparison(array &$tokens, int &$pos): float
    {
        $left = self::parseExpression($tokens, $pos);

        $operators = ['<', '<=', '>', '>=', '==', '!='];
        if ($pos >= count($tokens) || !is_string($tokens[$pos]) || !in_array($tokens[$pos], $operators, true)) {
            return $left;
        }

        $operator = $tokens[$pos];
        $pos++;
        $right = self::parseExpression($tokens, $pos);

        $equal = abs($left - $right) <= 1e-9;   // égalité de flottants tolérante

        $result = match ($operator) {
            '<'  => $left < $right && !$equal,
            '<=' => $left < $right || $equal,
            '>'  => $left > $right && !$equal,
            '>=' => $left > $right || $equal,
            '==' => $equal,
            '!=' => !$equal,
        };

        return $result ? 1.0 : 0.0;
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
            // parseTernary et non parseExpression : une parenthèse doit pouvoir
            // contenir une condition, ce qui rend les paliers imbricables.
            $value = self::parseTernary($tokens, $pos);
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
        // Pilote explicitement déclaré non volant ce jour-là : aucun point,
        // même si des balises avaient été validées avant la décision.
        if (!$pilot->fliesOnDay($day)) {
            return 0;
        }

        // Handicap du planeur affecté ce jour-là, à défaut celui du planeur
        // associé sur toute la compétition.
        $participant = $pilot->participantForDay($day, $comp->id);
        $handicap = $participant ? (float) $participant->handicap : 1.00;

        // Get validated turnpoints for this pilot on this day
        $validatedTurnpoints = PilotTurnpoint::where('pilot_id', $pilot->id)
            ->where('competition_day_id', $day->id)
            ->with('turnpoint')
            ->get();

        $totalScore = 0.0;
        $config     = self::scoringConfig($comp);   // lu une fois, pas par balise

        foreach ($validatedTurnpoints as $pt) {
            if ($pt->turnpoint) {
                $totalScore += self::pointsForTurnpoint($pt->turnpoint, $comp, $handicap, $config);
            }
        }

        return (int) round($totalScore);
    }

    /**
     * Formule et base en vigueur pour la compétition.
     *
     * @return array{formula: string, base: float}
     */
    public static function scoringConfig(Competition $comp): array
    {
        return [
            'formula' => Setting::get('scoring_formula', $comp->id)
                ?? '(DISTANCE_TURNPOINT * BASE) / COEF_PLANEUR',
            'base' => (float) (Setting::get('scoring_base', $comp->id) ?? 100),
        ];
    }

    /**
     * Points rapportés par une balise, formule appliquée.
     *
     * Extrait du calcul journalier pour que l'écran d'analyse IGC affiche
     * exactement ce qui sera compté, sans réimplémenter la règle.
     *
     * Une formule fautive rend 0 : le classement ne doit pas s'interrompre en
     * pleine épreuve à cause d'une erreur de saisie.
     */
    public static function pointsForTurnpoint(
        Turnpoint $tp,
        Competition $comp,
        float $handicap = 1.00,
        ?array $config = null
    ): float {
        // La configuration est passée par l'appelant lorsqu'il boucle sur des
        // balises, pour ne pas relire les réglages à chaque tour. Pas de cache
        // statique : il survivrait à une modification des réglages.
        $config ??= self::scoringConfig($comp);

        $distance = self::haversineKm(
            (float) $comp->start_lat,
            (float) $comp->start_lng,
            (float) $tp->lat,
            (float) $tp->lng
        );

        try {
            return self::evaluate($config['formula'], [
                'DISTANCE_TURNPOINT' => $distance,
                'BASE'               => $config['base'],
                'COEF_PLANEUR'       => $handicap,
                'HANDICAP'           => $handicap,
                // Valeur propre à la balise : sert aux barèmes fixes et aux
                // bonus, que la distance seule ne permet pas d'exprimer.
                'POINTS_BALISE'      => (float) ($tp->points ?? 0),
            ]);
        } catch (\RuntimeException) {
            return 0.0;
        }
    }
}
