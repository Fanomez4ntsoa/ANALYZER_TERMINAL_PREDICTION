<?php

namespace Tests\Unit\Probability;

use App\Models\FootballMatch;
use App\Services\Probability\KickoffPassedException;
use App\Services\Probability\PredictionService;
use Tests\TestCase;

/** Règle 5 : aucune prédiction écrite après le coup d'envoi, quel que soit l'appelant. */
class PredictionServiceKickoffTest extends TestCase
{
    private function match(string $kickoff, bool $contaminated = false): FootballMatch
    {
        $match = new FootballMatch(['home_team' => 'A', 'away_team' => 'B']);
        $match->id = 1;
        $match->match_date = now()->modify($kickoff);
        $match->post_kickoff_data = $contaminated;

        return $match;
    }

    public function test_kicked_off_match_is_refused_before_any_computation(): void
    {
        $this->expectException(KickoffPassedException::class);
        app(PredictionService::class)->computeAndStore($this->match('-1 minute'));
    }

    public function test_contaminated_match_is_refused_even_before_kickoff(): void
    {
        $this->expectException(KickoffPassedException::class);
        app(PredictionService::class)->computeAndStore($this->match('+2 hours', contaminated: true));
    }
}
