<?php

namespace App\Services;

class TempSource
{
    public string $source_type;
    public array $predictions;

    public function __construct(string $type, array $sourceData)
    {
        $this->source_type = $type;
        $this->predictions = $sourceData;
    }

    public function getPick(string $market): ?string
    {
        return match($market) {
            'winner' => $this->predictions['winner']['pick'] ?? 
                        ($this->source_type === 'C' ? $this->getWinnerFromProbs($this->predictions['winner'] ?? []) : null),
            'overUnder' => $this->predictions['overUnder']['pick'] ?? null,
            'btts' => $this->predictions['btts']['pick'] ?? null,
            'doubleChance' => $this->predictions['doubleChance']['pick'] ?? null,
            'exactScore' => $this->predictions['exactScore']['pick'] ?? null,
            default => null,
        };
    }

    public function getConfidence(string $market): int
    {
        return match($market) {
            'winner' => $this->predictions['winner']['confidence'] ?? 50,
            'overUnder' => $this->predictions['overUnder']['confidence'] ?? 50,
            'btts' => $this->predictions['btts']['confidence'] ?? 50,
            'doubleChance' => $this->predictions['doubleChance']['confidence'] ?? 50,
            'exactScore' => $this->predictions['exactScore']['confidence'] ?? 0,
            default => 50,
        };
    }

    private function getWinnerFromProbs(array $probs): string
    {
        $home = $probs['home'] ?? 0;
        $draw = $probs['draw'] ?? 0;
        $away = $probs['away'] ?? 0;
        
        $max = max($home, $draw, $away);
        
        if ($max === $home) return '1';
        if ($max === $draw) return 'X';
        return '2';
    }
}