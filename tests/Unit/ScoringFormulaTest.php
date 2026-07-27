<?php

namespace Tests\Unit;

use App\Services\ScoringService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScoringFormulaTest extends TestCase
{
    private function evaluate(string $formula, array $variables = []): float
    {
        return ScoringService::evaluate($formula, $variables);
    }

    // ─── Arithmétique de base, inchangée ─────────────────────────────────────

    public function test_arithmetique_et_priorites(): void
    {
        $this->assertSame(14.0, $this->evaluate('2 + 3 * 4'));
        $this->assertSame(20.0, $this->evaluate('(2 + 3) * 4'));
        $this->assertSame(-6.0, $this->evaluate('-2 * 3'));
        $this->assertSame(2.5, $this->evaluate('5 / 2'));
    }

    public function test_formule_historique_avec_variables(): void
    {
        $score = $this->evaluate('(DISTANCE_TURNPOINT * BASE) / COEF_PLANEUR', [
            'DISTANCE_TURNPOINT' => 60.0,
            'BASE'               => 100.0,
            'COEF_PLANEUR'       => 1.2,
        ]);

        $this->assertSame(5000.0, $score);
    }

    public function test_division_par_zero_rejetee(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->evaluate('10 / 0');
    }

    // ─── Comparaisons ────────────────────────────────────────────────────────

    public function test_comparaisons_rendent_un_ou_zero(): void
    {
        $this->assertSame(1.0, $this->evaluate('3 < 5'));
        $this->assertSame(0.0, $this->evaluate('5 < 3'));
        $this->assertSame(1.0, $this->evaluate('5 <= 5'));
        $this->assertSame(1.0, $this->evaluate('7 > 2'));
        $this->assertSame(1.0, $this->evaluate('4 >= 4'));
        $this->assertSame(1.0, $this->evaluate('4 == 4'));
        $this->assertSame(1.0, $this->evaluate('4 != 5'));
        $this->assertSame(0.0, $this->evaluate('4 != 4'));
    }

    public function test_comparaison_tolerante_aux_flottants(): void
    {
        // 0.1 + 0.2 ne vaut pas exactement 0.3 en binaire.
        $this->assertSame(1.0, $this->evaluate('0.1 + 0.2 == 0.3'));
    }

    public function test_operateur_incomplet_rejete(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->evaluate('3 = 3');
    }

    // ─── Barème par paliers ──────────────────────────────────────────────────

    /**
     * Le barème réel : 10 points jusqu'à 50 km, 20 de 50 à 90, 30 au-delà.
     */
    public static function paliersProvider(): array
    {
        return [
            'sous le premier palier' => [10.0, 10.0],
            'juste avant 50 km'      => [49.9, 10.0],
            'exactement 50 km'       => [50.0, 10.0],
            'entre 50 et 90'         => [70.0, 20.0],
            'exactement 90 km'       => [90.0, 20.0],
            'au-dela de 90'          => [120.0, 30.0],
        ];
    }

    #[DataProvider('paliersProvider')]
    public function test_bareme_par_paliers(float $distance, float $attendu): void
    {
        $formule = 'DISTANCE_TURNPOINT <= 50 ? 10 : (DISTANCE_TURNPOINT <= 90 ? 20 : 30)';

        $this->assertSame($attendu, $this->evaluate($formule, [
            'DISTANCE_TURNPOINT' => $distance,
            'POINTS_BALISE'      => 0.0,
        ]));
    }

    /**
     * Barème complet : une balise valorisée explicitement l'emporte sur les
     * paliers — c'est ainsi que se traitent les super-bonus à 100 points.
     */
    public function test_bonus_prime_sur_les_paliers(): void
    {
        $formule = 'POINTS_BALISE > 0 ? POINTS_BALISE '
            . ': (DISTANCE_TURNPOINT <= 50 ? 10 : (DISTANCE_TURNPOINT <= 90 ? 20 : 30))';

        $bonus = $this->evaluate($formule, [
            'DISTANCE_TURNPOINT' => 30.0,
            'POINTS_BALISE'      => 100.0,
        ]);
        $this->assertSame(100.0, $bonus, 'Le bonus doit primer malgré une faible distance');

        $ordinaire = $this->evaluate($formule, [
            'DISTANCE_TURNPOINT' => 120.0,
            'POINTS_BALISE'      => 0.0,
        ]);
        $this->assertSame(30.0, $ordinaire, 'Sans bonus, le palier de distance s\'applique');
    }

    public function test_ternaire_combinable_avec_arithmetique(): void
    {
        $this->assertSame(24.0, $this->evaluate('(D > 50 ? 20 : 10) / H', ['D' => 60.0, 'H' => 1.0]) + 4.0);
        $this->assertSame(20.0, $this->evaluate('(D > 50 ? 20 : 10) / H', ['D' => 60.0, 'H' => 1.0]));
        $this->assertSame(10.0, $this->evaluate('(D > 50 ? 20 : 10) / H', ['D' => 40.0, 'H' => 1.0]));
    }

    public function test_ternaire_sans_alternative_rejete(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->evaluate('1 ? 10');
    }

    public function test_paliers_chaines_sans_parentheses(): void
    {
        // Le ternaire est associatif à droite : l'imbrication reste correcte
        // même sans parenthèses, ce qui est la forme la plus lisible.
        $formule = 'D <= 50 ? 10 : D <= 90 ? 20 : 30';

        $this->assertSame(10.0, $this->evaluate($formule, ['D' => 20.0]));
        $this->assertSame(20.0, $this->evaluate($formule, ['D' => 70.0]));
        $this->assertSame(30.0, $this->evaluate($formule, ['D' => 200.0]));
    }

    // ─── Formules mal formées ────────────────────────────────────────────────

    public static function formulesInvalidesProvider(): array
    {
        return [
            'parenthèse non fermée'   => ['(2 + 3'],
            'parenthèse en trop'      => ['2 + 3)'],
            'opérateur sans opérande' => ['2 +'],
            'caractère inconnu'       => ['2 § 3'],
            'formule vide'            => [''],
            'deux points orphelin'    => ['2 : 3'],
        ];
    }

    #[DataProvider('formulesInvalidesProvider')]
    public function test_formule_invalide_rejetee(string $formule): void
    {
        // Une formule fautive doit lever, jamais renvoyer un score silencieux.
        $this->expectException(\RuntimeException::class);
        $this->evaluate($formule);
    }

    public function test_variable_inconnue_laissee_telle_quelle_est_rejetee(): void
    {
        // INCONNUE n'est pas substituée : elle reste des lettres, donc invalide.
        $this->expectException(\RuntimeException::class);
        $this->evaluate('INCONNUE * 2', ['DISTANCE_TURNPOINT' => 10.0]);
    }

    // ─── Distance ────────────────────────────────────────────────────────────

    public function test_haversine_distance_connue(): void
    {
        // Thouars (départ de la Wassmer Cup) → Le Mans, ~155 km à vol d'oiseau.
        $km = ScoringService::haversineKm(46.962418, -0.157499, 47.9489, 0.2014);

        $this->assertEqualsWithDelta(115.0, $km, 5.0);
    }

    public function test_haversine_distance_nulle(): void
    {
        $this->assertSame(0.0, ScoringService::haversineKm(46.9, -0.15, 46.9, -0.15));
    }
}
