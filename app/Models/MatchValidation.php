<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchValidation extends Model
{
    protected $fillable = [
        'match_id',
        'recommendation_id',
        'is_combo',
        'combo_index',
        'validated',
        'validated_at',
    ];

    protected $casts = [
        'validated' => 'boolean',
        'is_combo' => 'boolean',
        'validated_at' => 'datetime',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(FootballMatch::class, 'match_id', 'id');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class, 'recommendation_id', 'id');
    }
}
