<?php

namespace Tests\Unit\Terminal;

use App\Models\Prediction;
use App\Support\Terminal\Fmt;
use App\Support\Terminal\MarketNature;
use App\Support\Terminal\SystemState;
use PHPUnit\Framework\TestCase;

class FormatAndNatureTest extends TestCase
{
    public function test_french_format_with_true_minus_sign(): void
    {
        $this->assertSame('41,2', Fmt::percent(0.41234));
        $this->assertSame('2,38', Fmt::odds(2.38));
        $this->assertSame('13,00', Fmt::odds(13.0));
        $this->assertSame('+2,1', Fmt::points(0.0207));
        $this->assertSame("\u{2212}2,1", Fmt::points(-0.0207));
        $this->assertSame("\u{2212}0,051", Fmt::number(-0.05149, 3));
    }

    public function test_edge_rounding_to_zero_has_no_sign(): void
    {
        $this->assertSame('0,0', Fmt::points(-0.0004));
        $this->assertSame('0,0', Fmt::points(0.0003));
        $this->assertSame(0, Fmt::sign(-0.0004));
    }

    public function test_missing_values_render_empty_never_estimated(): void
    {
        $this->assertSame('', Fmt::percent(null));
        $this->assertSame('', Fmt::odds(null));
        $this->assertSame('', Fmt::points(null));
    }

    public function test_adjustment_market_edge_is_never_coloured(): void
    {
        // Résidu d'ajustement réel du 1X2 (Inter · Udinese, 14/09/2026)
        $this->assertNull(MarketNature::edgeTone(Prediction::MARKET_WINNER, -0.0095));
        $this->assertNull(MarketNature::edgeTone(Prediction::MARKET_DOUBLE_CHANCE, 0.0274));
        $this->assertNull(MarketNature::edgeTone(Prediction::MARKET_OVER_UNDER_25, 0.0003));
        $this->assertSame('Ajust.', MarketNature::label(Prediction::MARKET_WINNER));
    }

    public function test_derived_market_edge_carries_its_sign_only(): void
    {
        $this->assertSame('pos', MarketNature::edgeTone(Prediction::MARKET_BTTS, 0.0207));
        $this->assertSame('neg', MarketNature::edgeTone(Prediction::MARKET_BTTS, -0.0207));
        $this->assertNull(MarketNature::edgeTone(Prediction::MARKET_BTTS, 0.0004));
        $this->assertNull(MarketNature::edgeTone(Prediction::MARKET_BTTS, null));
        $this->assertSame('Dérivé', MarketNature::label(Prediction::MARKET_BTTS));
    }

    public function test_unclassified_market_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MarketNature::edgeTone('overUnder35', 0.02);
    }

    public function test_only_the_most_severe_critical_state_goes_inverse(): void
    {
        $notice = new SystemState(SystemState::NOTICE, 'Config', 'écart de configuration');
        $first = new SystemState(SystemState::CRITICAL, 'Pipeline', 'aucun passage aujourd\'hui');
        $second = new SystemState(SystemState::CRITICAL, 'Erreur', 'quota épuisé');
        $warning = new SystemState(SystemState::WARNING, 'Pipeline', 'incomplet');

        ['inverse' => $inverse, 'lines' => $lines] = SystemState::arrange([$notice, $first, $warning, $second]);

        $this->assertSame($first, $inverse);
        $this->assertSame([$second, $warning, $notice], $lines);
    }

    public function test_no_inverse_without_critical_state(): void
    {
        $warning = new SystemState(SystemState::WARNING, 'Pipeline', 'incomplet');

        $this->assertSame(['inverse' => null, 'lines' => [$warning]], SystemState::arrange([$warning]));
        $this->assertSame(['inverse' => null, 'lines' => []], SystemState::arrange([]));
    }
}
