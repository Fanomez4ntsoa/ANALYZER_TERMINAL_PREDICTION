<?php

namespace App\Services\Betting;

class ParserService
{
    /**
     * Parse Source A (good-sport.co)
     */
    public function parseSourceA(string $rawText): ?array
    {
        $result = [];
        
        // Extraire probabilités
        if (preg_match('/Home:\s*(\d+)%.*?Draw:\s*(\d+)%.*?Away:\s*(\d+)%/i', $rawText, $probMatch)) {
            $probHome = (int) $probMatch[1];
            $probDraw = (int) $probMatch[2];
            $probAway = (int) $probMatch[3];
            
            // Déterminer le pick
            $probs = [
                ['value' => '1', 'prob' => $probHome],
                ['value' => 'X', 'prob' => $probDraw],
                ['value' => '2', 'prob' => $probAway],
            ];
            
            $highest = collect($probs)->sortByDesc('prob')->first();
            
            $result['winner'] = [
                'pick' => $highest['value'],
                'confidence' => $highest['prob'],
                'probHome' => $probHome,
                'probDraw' => $probDraw,
                'probAway' => $probAway,
            ];
        }
        
        // Extraire Over/Under
        if (preg_match('/Under\/Over\s+2\.5\s*[→:]\s*([OU])/i', $rawText, $ouMatch)) {
            $result['overUnder'] = [
                'pick' => strtoupper($ouMatch[1]) === 'O' ? 'Over' : 'Under',
            ];
        }
        
        // Extraire BTTS
        if (preg_match('/BTTS\s*[→:]\s*(Yes|No)/i', $rawText, $bttsMatch)) {
            $result['btts'] = [
                'pick' => $bttsMatch[1],
            ];
        }
        
        // Extraire Double Chance
        if (preg_match('/Double\s+Chance\s*[→:]\s*(1X|X2|12)/i', $rawText, $dcMatch)) {
            $result['doubleChance'] = [
                'pick' => $dcMatch[1],
            ];
        }
        
        // Extraire Score exact
        if (preg_match('/Correct\s+Score\s*[→:]\s*(\d+)[-:](\d+)/i', $rawText, $scoreMatch)) {
            $result['exactScore'] = [
                'pick' => "{$scoreMatch[1]}-{$scoreMatch[2]}",
            ];
        }
        
        return !empty($result) ? $result : null;
    }
    
    /**
     * Parse Source B (mybets.today)
     */
    public function parseSourceB(string $rawText): ?array
    {
        $result = [];
        
        // Extraire Winner
        if (preg_match('/Winner\s*:\s*\w+\s*\((\d+)%/i', $rawText, $winnerMatch)) {
            $result['winner'] = [
                'pick' => '1', // Ajuster selon la logique
                'confidence' => (int) $winnerMatch[1],
            ];
        }
        
        // Extraire Total Goals
        if (preg_match('/Total\s+Goals\s*:\s*(Over|Under)\s+2\.5\s*\((\d+)%/i', $rawText, $ouMatch)) {
            $result['overUnder'] = [
                'pick' => $ouMatch[1],
                'confidence' => (int) $ouMatch[2],
            ];
        }
        
        // Extraire BTTS
        if (preg_match('/Both\s+Teams\s+To\s+Score\s*:\s*(Yes|No)\s*\((\d+)%/i', $rawText, $bttsMatch)) {
            $result['btts'] = [
                'pick' => $bttsMatch[1],
                'confidence' => (int) $bttsMatch[2],
            ];
        }
        
        return !empty($result) ? $result : null;
    }
    
    /**
     * Parse Source C
     */
    public function parseSourceC(string $rawText): ?array
    {
        $result = [];
        
        // Extraire probabilités 1X2
        if (preg_match('/1:\s*(\d+)%.*?X:\s*(\d+)%.*?2:\s*(\d+)%/i', $rawText, $probMatch)) {
            $result['winner'] = [
                'home' => (int) $probMatch[1],
                'draw' => (int) $probMatch[2],
                'away' => (int) $probMatch[3],
            ];
        }
        
        // Autres extractions...
        
        return !empty($result) ? $result : null;
    }
    
    /**
     * Nettoie le texte avant parsing
     */
    public function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}