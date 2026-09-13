<?php

namespace App\Console\Commands;

use App\Models\AdvancedData;
use App\Models\Combo;
use App\Models\FootballMatch;
use App\Models\MatchValidation;
use App\Models\Recommendation;
use App\Models\Source;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportReactHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:react-history {file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import React history JSON into Laravel database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = storage_path($this->argument('file'));
        
        if (!file_exists($filePath)) {
            $this->error("❌ Fichier non trouvé : {$filePath}");
            return 1;
        }
        
        $this->info("📂 Lecture du fichier : {$filePath}");
        $json = json_decode(file_get_contents($filePath), true);
        
        if (!$json || !isset($json['matches'])) {
            $this->error("❌ Format JSON invalide");
            return 1;
        }
        
        $totalMatches = count($json['matches']);
        $this->info("📊 {$totalMatches} matchs à analyser");
        
        $imported = 0;
        $skipped = 0;
        
        DB::transaction(function () use ($json, &$imported, &$skipped) {
            $bar = $this->output->createProgressBar(count($json['matches']));
            $bar->start();
            
            foreach ($json['matches'] as $matchData) {
                // Skip les matchs sans Layer 2
                if (!$this->hasValidLayer2Data($matchData)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }
                
                $this->importMatch($matchData);
                $imported++;
                $bar->advance();
            }
            
            $bar->finish();
            $this->newLine();
        });
        
        $this->newLine();
        $this->info("✅ Import terminé !");
        $this->info("📊 {$imported} matchs importés avec Layer 2");
        if ($skipped > 0) {
            $this->warn("⏭️  {$skipped} matchs skippés (sans Layer 2)");
        }
        $this->newLine();
        
        $this->displayStats();
        
        return 0;
    }

    private function importMatch(array $data)
    {
        // 1. Créer le match
        $match = FootballMatch::create([
            'react_id' => $data['id'],
            'version' => '1.0',
            'home_team' => $data['teams']['home'],
            'away_team' => $data['teams']['away'],
            'match_date' => Carbon::parse($data['date']),
            'competition' => $data['competition'],
            'odds_home' => $data['odds']['home'],
            'odds_draw' => $data['odds']['draw'],
            'odds_away' => $data['odds']['away'],
            'global_confidence' => $data['analysis']['globalConfidence'],
            'context' => $data['analysis']['context'],
            'sources_data' => $data['sources'],
            'validated' => $data['validation']['validated'] ?? false,
            'validated_at' => isset($data['validation']['validatedAt']) 
                ? Carbon::parse($data['validation']['validatedAt']) 
                : null,
        ]);
        
        // 2. Mettre à jour les scores Layer 2
        if (isset($data['analysis']['advancedInsights'])) {
            $advancedInsights = $data['analysis']['advancedInsights'];
            
            $match->update([
                'layer1_score' => $advancedInsights['layer1Score'] ?? null,
                'layer2_score' => $advancedInsights['layer2Score'] ?? null,
                'convergence' => $advancedInsights['convergence'] ?? null,
            ]);
        }
        
        // 3. Créer les sources (A, B, C)
        $this->createSources($match, $data['sources']);
        
        // 4. Créer les recommandations avec validation
        if (isset($data['validation']['picks'])) {
            foreach ($data['validation']['picks'] as $pick) {
                $recommendation = Recommendation::create([
                    'match_id' => $match->id,
                    'market' => $pick['market'],
                    'bet' => $pick['bet'],
                    'confidence' => $pick['confidence'],
                    'score' => $pick['score'],
                    'level' => $pick['level'],
                    'odds' => $this->estimateOdds($pick['market'], $pick['bet']),
                    'consensus_type' => $pick['consensus'],
                    'consensus_agreement' => $this->getAgreement($pick['consensus']),
                    'predictions' => json_encode($pick['predictions']),
                    'special_rule' => $pick['specialRule'],
                    'logic' => $this->generateLogic($pick),
                    'coherent' => true,
                    'validated' => $pick['success'],
                ]);
                
                // Créer l'entrée de validation
                if ($data['validation']['validated']) {
                    MatchValidation::create([
                        'match_id' => $match->id,
                        'recommendation_id' => $recommendation->id,
                        'is_combo' => false,
                        'validated' => $pick['success'],
                        'validated_at' => Carbon::parse($data['validation']['validatedAt']),
                    ]);
                }
            }
        }
        
        // 5. Créer les combinés
        if (isset($data['validation']['combos'])) {
            foreach ($data['validation']['combos'] as $index => $comboData) {
                Combo::create([
                    'match_id' => $match->id,
                    'name' => $comboData['name'],
                    'bets' => json_encode($comboData['bets']),
                    'total_odds' => $comboData['totalOdds'],
                    'confidence' => $comboData['confidence'],
                ]);
                
                // Validation du combiné
                if ($data['validation']['validated']) {
                    MatchValidation::create([
                        'match_id' => $match->id,
                        'is_combo' => true,
                        'combo_index' => $index,
                        'validated' => $comboData['success'],
                        'validated_at' => Carbon::parse($data['validation']['validatedAt']),
                    ]);
                }
            }
        }

        // 6. Créer les données Layer 2 (SANS json_encode - Laravel le fait auto)
        AdvancedData::create([
            'match_id' => $match->id,
            
            // ✅ Passer les arrays DIRECTEMENT - Laravel encode automatiquement
            'tactical_data' => $data['tacticalData'] ?? null,
            'sofascore_data' => $data['sofascoreData'] ?? null,
            'footystats_data' => $data['footyStatsData'] ?? null,
            'fbref_data' => $data['fbrefData'] ?? null,
            'context_data' => $data['contextData'] ?? null,
            
            // Résultats d'analyse
            'dimensions' => $data['analysis']['advancedInsights']['dimensions'] ?? null,
            'tactical_insights' => $data['analysis']['advancedInsights']['tacticalInsights'] ?? null,
            'original_recommendations' => $data['analysis']['advancedInsights']['originalRecommendations'] ?? null,
            'detailed_explanation' => $data['analysis']['advancedInsights']['detailedExplanation'] ?? null,
        ]);
    }

    private function hasValidLayer2Data(array $data): bool
    {
        return ($data['tacticalData'] ?? null) !== null ||
               ($data['sofascoreData'] ?? null) !== null ||
               ($data['footyStatsData'] ?? null) !== null ||
               ($data['fbrefData'] ?? null) !== null ||
               ($data['contextData'] ?? null) !== null ||
               ($data['analysis']['advancedInsights'] ?? null) !== null;
    }

    private function arrayOrNull($value)
    {
        return ($value !== null && $value !== []) ? $value : null;
    }
    
    private function createSources(FootballMatch $match, array $sources)
    {
        foreach (['sourceA' => 'A', 'sourceB' => 'B', 'sourceC' => 'C'] as $key => $type) {
            if (!isset($sources[$key])) continue;
            
            Source::create([
                'match_id' => $match->id,
                'source_type' => $type,
                'winner_pick' => $sources[$key]['winner']['pick'] ?? null,
                'winner_confidence' => $sources[$key]['winner']['confidence'] ?? null,
                'overunder_pick' => $sources[$key]['overUnder']['pick'] ?? null,
                'overunder_confidence' => $sources[$key]['overUnder']['confidence'] ?? null,
                'btts_pick' => $sources[$key]['btts']['pick'] ?? null,
                'btts_confidence' => $sources[$key]['btts']['confidence'] ?? null,
                'double_chance_pick' => $sources[$key]['doubleChance']['pick'] ?? null,
                'double_chance_confidence' => $sources[$key]['doubleChance']['confidence'] ?? null,
                'exact_score_pick' => $sources[$key]['exactScore']['pick'] ?? null,
                'exact_score_confidence' => $sources[$key]['exactScore']['confidence'] ?? null,
                'predictions' => json_encode($sources[$key])
            ]);
        }
    }
    
    private function estimateOdds(string $market, string $bet): float
    {
        $defaults = [
            'doubleChance' => ['1X' => 1.35, 'X2' => 1.30, '12' => 1.25],
            'overUnder' => ['Over' => 1.70, 'Under' => 1.65],
            'btts' => ['Yes' => 1.80, 'No' => 1.65],
            'exactScore' => 7.50,
        ];
        
        return $defaults[$market][$bet] ?? $defaults[$market] ?? 1.50;
    }
    
    private function getAgreement(string $consensus): int
    {
        return match($consensus) {
            'TOTAL' => 3,
            'MAJORITÉ' => 2,
            'CONFLIT' => 1,
            default => 0
        };
    }
    
    private function generateLogic(array $pick): string
    {
        $parts = [];
        $parts[] = $pick['consensus'] === 'TOTAL' ? '✅ Consensus total' : '⚖️ Majorité';
        if ($pick['specialRule']) {
            $parts[] = "🌟 {$pick['specialRule']}";
        }
        return implode(' • ', $parts);
    }
    
    private function displayStats()
    {
        // Compter les matchs importés (avec react_id)
        $total = DB::table('matches')->whereNotNull('react_id')->count();
        $validated = DB::table('matches')->whereNotNull('react_id')->where('validated', true)->count();
        
        // Compter les recommandations
        $matchIds = DB::table('matches')->whereNotNull('react_id')->pluck('id');
        $recommendations = DB::table('recommendations')->whereIn('match_id', $matchIds)->count();
        $successful = DB::table('recommendations')
            ->whereIn('match_id', $matchIds)
            ->where('validated', true)
            ->count();
        
        $this->newLine();
        $this->info("📊 STATISTIQUES D'IMPORT :");
        
        if ($total === 0) {
            $this->warn("⚠️ Aucune donnée avec react_id trouvée !");
            $this->info("💡 Nombre total de matchs en BDD : " . DB::table('matches')->count());
            return;
        }
        
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Matchs importés (react_id)', $total],
                ['Matchs validés', $validated],
                ['Recommandations', $recommendations],
                ['Recommandations réussies', $successful],
                ['Taux de réussite', $recommendations > 0 ? round(($successful / $recommendations) * 100, 1) . '%' : 'N/A'],
            ]
        );
        
        $this->info("✅ Total matchs en BDD : " . DB::table('matches')->count());
    }
}
