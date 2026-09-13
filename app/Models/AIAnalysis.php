<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIAnalysis extends Model
{
    protected $table = 'ai_analysis';

    protected $fillable = [
        'match_id',
        'value_output', 'risk_output', 'market_output', 'narrative_output',
        'match_analyst_output',
        'final_decision', 'ai_score', 'ai_vs_real_result',
        'total_input_tokens', 'total_output_tokens',
    ];

    protected $casts = [
        'value_output' => 'array',
        'risk_output' => 'array',
        'market_output' => 'array',
        'narrative_output' => 'array',
        'match_analyst_output' => 'array',
        'final_decision' => 'array',
        'ai_vs_real_result' => 'array',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id');
    }
}
