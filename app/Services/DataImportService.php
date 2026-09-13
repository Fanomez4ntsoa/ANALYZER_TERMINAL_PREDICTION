<?php

namespace App\Services;

use App\Models\FootballMatch;
use App\Models\AdvancedData;
use App\Models\Recommendation;
use App\Models\Combo;
use App\Models\MatchValidation;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DataImportService
{
    /**
     * Importer un fichier (JSON ou CSV)
     */
    public function import($file, string $format)
    {
        $content = file_get_contents($file->getRealPath());
        
        switch ($format) {
            case 'json_complete':
                return $this->importJsonComplete($content);
            
            case 'json_analysis':
                return $this->importJsonAnalysis($content);
            
            case 'csv':
                return $this->importCsv($content);
            
            default:
                throw new \Exception("Format non supporté: {$format}");
        }
    }

    /**
     * Import JSON Complet (export React complet)
     */
    private function importJsonComplete(string $content)
    {
        $data = json_decode($content, true);
        
        if (!$data || !isset($data['matches'])) {
            throw new \Exception("Format JSON invalide");
        }

        $report = [
            'total' => count($data['matches']),
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        DB::beginTransaction();
        
        try {
            foreach ($data['matches'] as $matchData) {
                try {
                    $result = $this->importMatch($matchData);
                    
                    if ($result === 'imported') {
                        $report['imported']++;
                    } elseif ($result === 'updated') {
                        $report['updated']++;
                    } else {
                        $report['skipped']++;
                    }
                    
                } catch (\Exception $e) {
                    $report['errors'][] = [
                        'match' => $matchData['teams']['home'] . ' vs ' . $matchData['teams']['away'],
                        'error' => $e->getMessage()
                    ];
                    Log::error('Import match error: ' . $e->getMessage(), ['match' => $matchData]);
                }
            }
            
            DB::commit();
            return $report;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Import JSON Analyse (export condensé)
     */
    private function importJsonAnalysis(string $content)
    {
        $data = json_decode($content, true);
        
        if (!$data || !isset($data['detailedAnalysis'])) {
            throw new \Exception("Format JSON Analyse invalide");
        }

        // Ce format ne contient que des insights
        // On pourrait le stocker dans une table séparée ou juste retourner les insights
        return [
            'total' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'insights' => $data['insights'] ?? [],
            'message' => 'Import d\'insights uniquement (pas de matchs)'
        ];
    }

    /**
     * Import CSV
     */
    private function importCsv(string $content)
    {
        $lines = array_filter(explode("\n", $content));
        $headers = str_getcsv(array_shift($lines));
        
        $report = [
            'total' => count($lines),
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        DB::beginTransaction();
        
        try {
            foreach ($lines as $line) {
                $row = str_getcsv($line);
                
                if (count($row) !== count($headers)) {
                    continue;
                }
                
                $data = array_combine($headers, $row);
                
                try {
                    $result = $this->importCsvRow($data);
                    
                    if ($result === 'imported') {
                        $report['imported']++;
                    } elseif ($result === 'updated') {
                        $report['updated']++;
                    } else {
                        $report['skipped']++;
                    }
                    
                } catch (\Exception $e) {
                    $report['errors'][] = [
                        'match' => $data['Match'] ?? 'Unknown',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            DB::commit();
            return $report;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Importer un match depuis le JSON complet
     */
    private function importMatch(array $matchData)
    {
        // Vérifier si le match existe déjà (par react_id ou par équipes + date)
        $existingMatch = FootballMatch::where('react_id', $matchData['id'])
            ->orWhere(function($query) use ($matchData) {
                $query->where('home_team', $matchData['teams']['home'])
                      ->where('away_team', $matchData['teams']['away'])
                      ->whereDate('match_date', Carbon::parse($matchData['date'])->format('Y-m-d'));
            })
            ->first();

        $isUpdate = $existingMatch !== null;

        // Préparer les données du match
        $matchDbData = [
            'react_id' => $matchData['id'],
            'home_team' => $matchData['teams']['home'],
            'away_team' => $matchData['teams']['away'],
            'match_date' => Carbon::parse($matchData['date']),
            'competition' => $matchData['competition'],
            'global_confidence' => $matchData['globalConfidence'] ?? null,
            'context' => $matchData['context'] ?? null,
            'validated' => isset($matchData['validations']) && !empty($matchData['validations']),
            'validated_at' => isset($matchData['validations']) ? Carbon::now() : null,
        ];

        // Ajouter les cotes si disponibles
        if (isset($matchData['odds'])) {
            $matchDbData = array_merge($matchDbData, $this->mapOdds($matchData['odds']));
        }

        // Créer ou mettre à jour le match
        if ($isUpdate) {
            $existingMatch->update($matchDbData);
            $match = $existingMatch;
        } else {
            $match = FootballMatch::create($matchDbData);
        }

        // Importer les recommandations
        if (isset($matchData['recommendations'])) {
            $this->importRecommendations($match, $matchData['recommendations']);
        }

        // Importer les combos
        if (isset($matchData['combos'])) {
            $this->importCombos($match, $matchData['combos']);
        }

        // Importer les sources (predictions)
        if (isset($matchData['recommendations'])) {
            $this->importSources($match, $matchData['recommendations']);
        }

        return $isUpdate ? 'updated' : 'imported';
    }

    /**
     * Mapper les cotes depuis le format React vers Laravel
     */
    private function mapOdds(array $odds): array
    {
        return [
            'odds_home' => $odds['1'] ?? null,
            'odds_draw' => $odds['X'] ?? null,
            'odds_away' => $odds['2'] ?? null,
            'odds_over_2_5' => $odds['Over2.5'] ?? null,
            'odds_under_2_5' => $odds['Under2.5'] ?? null,
            'odds_btts_yes' => $odds['BTTS_Yes'] ?? null,
            'odds_btts_no' => $odds['BTTS_No'] ?? null,
            'odds_dc_1x' => $odds['DC_1X'] ?? null,
            'odds_dc_12' => $odds['DC_12'] ?? null,
            'odds_dc_x2' => $odds['DC_X2'] ?? null,
        ];
    }

    /**
     * Importer les recommandations d'un match
     */
    private function importRecommendations(FootballMatch $match, array $recommendations)
    {
        // Supprimer les anciennes recommandations si c'est une mise à jour
        $match->recommendations()->delete();

        foreach ($recommendations as $recData) {
            $recommendation = Recommendation::create([
                'match_id' => $match->id,
                'market' => $recData['market'],
                'bet' => $recData['bet'],
                'confidence' => $recData['confidence'],
                'score' => $recData['score'] ?? null,
                'level' => $recData['level'] ?? null,
                'odds' => $recData['odds'] ?? null,
                'consensus_type' => $recData['consensus']['type'] ?? null,
                'consensus_agreement' => $recData['consensus']['agreement'] ?? null,
                'predictions' => json_encode($recData['predictions'] ?? []),
                'special_rule' => $recData['specialRule']['type'] ?? null,
                'special_rule_reason' => $recData['specialRule']['reason'] ?? null,
                'logic' => $recData['logic'] ?? null,
                'validated' => $recData['result'] ?? null,
            ]);

            // Créer la validation si présente
            if (isset($recData['result'])) {
                MatchValidation::create([
                    'match_id' => $match->id,
                    'recommendation_id' => $recommendation->id,
                    'is_combo' => false,
                    'validated' => $recData['result'],
                    'validated_at' => Carbon::now(),
                ]);
            }
        }
    }

    /**
     * Importer les combos d'un match
     */
    private function importCombos(FootballMatch $match, array $combos)
    {
        // Supprimer les anciens combos
        $match->combos()->delete();

        foreach ($combos as $index => $comboData) {
            $combo = Combo::create([
                'match_id' => $match->id,
                'name' => $comboData['name'],
                'bets' => $comboData['bets'],
                'total_odds' => $comboData['totalOdds'],
                'confidence' => $comboData['confidence'],
                'priority' => $comboData['priority'] ?? null,
                'validated' => $comboData['result'] ?? null,
            ]);

            // Créer la validation si présente
            if (isset($comboData['result'])) {
                MatchValidation::create([
                    'match_id' => $match->id,
                    'recommendation_id' => null,
                    'is_combo' => true,
                    'combo_index' => $index,
                    'validated' => $comboData['result'],
                    'validated_at' => Carbon::now(),
                ]);
            }
        }
    }

    /**
     * Importer les sources (predictions)
     */
    private function importSources(FootballMatch $match, array $recommendations)
    {
        // Supprimer les anciennes sources
        $match->sources()->delete();

        $sourcesByType = ['A' => [], 'B' => [], 'C' => []];

        foreach ($recommendations as $recData) {
            if (isset($recData['predictions'])) {
                foreach ($recData['predictions'] as $pred) {
                    $sourceType = $pred['source'];
                    $sourcesByType[$sourceType][] = [
                        'market' => $recData['market'],
                        'value' => $pred['value'],
                        'confidence' => $pred['confidence'],
                    ];
                }
            }
        }

        foreach ($sourcesByType as $type => $predictions) {
            if (!empty($predictions)) {
                Source::create([
                    'match_id' => $match->id,
                    'source_type' => $type,
                    'predictions' => json_encode($predictions),
                ]);
            }
        }
    }

    /**
     * Importer une ligne CSV
     */
    private function importCsvRow(array $data)
    {
        // Parser le match depuis le CSV
        [$homeTeam, $awayTeam] = explode(' vs ', str_replace('"', '', $data['Match']));
        
        $matchDate = Carbon::createFromFormat('Y-m-d', $data['Date']);
        
        // Trouver ou créer le match
        $match = FootballMatch::firstOrCreate([
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'match_date' => $matchDate,
        ], [
            'competition' => str_replace('"', '', $data['Competition'] ?? 'Unknown'),
            'validated' => true,
            'validated_at' => Carbon::now(),
        ]);

        // Créer ou mettre à jour la recommandation
        $recommendation = Recommendation::updateOrCreate([
            'match_id' => $match->id,
            'market' => $this->csvMarketToDb($data['Market']),
            'bet' => $data['Pick'],
        ], [
            'confidence' => (int)$data['Confidence'],
            'consensus_type' => $data['Consensus'],
            'validated' => $data['Result'] === 'WIN',
        ]);

        // Créer la validation
        MatchValidation::updateOrCreate([
            'match_id' => $match->id,
            'recommendation_id' => $recommendation->id,
            'is_combo' => false,
        ], [
            'validated' => $data['Result'] === 'WIN',
            'validated_at' => Carbon::now(),
        ]);

        return 'imported';
    }

    /**
     * Convertir le nom du marché CSV vers le format DB
     */
    private function csvMarketToDb(string $market): string
    {
        $mapping = [
            '1X2' => 'winner',
            'O/U 2.5' => 'overUnder',
            'BTTS' => 'btts',
            'DC' => 'doubleChance',
            'Score' => 'exactScore',
        ];

        return $mapping[$market] ?? $market;
    }
}