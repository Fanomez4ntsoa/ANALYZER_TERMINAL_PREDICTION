# AUDIT SYSTÈME — football-analyzer-web

Audit en lecture seule réalisé le 2026-09-13 sur l'état du dépôt (dernière activité observée dans les logs : 2026-05-05).
Destiné à un lecteur qui n'a pas accès au code. Les chemins sont relatifs à la racine du projet, les numéros de ligne correspondent aux fichiers tels qu'audités.

Limites de l'audit : la base MariaDB (`football-analyzer` sur 127.0.0.1:3306) a refusé la connexion depuis l'environnement d'audit ("Access denied for user admin"). Les volumes de données ne sont donc pas vérifiés directement ; ils viennent de `Claude.md` (roadmap interne) et des rapports JSON présents dans `storage/app/private/reports/`. Le dépôt n'est pas versionné (pas de `.git`), aucun historique de commits n'est disponible.

---

## 1. Vue d'ensemble

**But.** Application web personnelle de pronostics football. Elle importe les matchs du jour et leurs cotes, calcule des probabilités par marché (1X2, Over/Under 2.5, BTTS, Double Chance, score exact) avec un modèle Poisson maison alimenté surtout par les cotes bookmaker, fait relire le résultat par plusieurs "agents" Claude (LLM), et assemble des combinés de 4 à 5 matchs visant une cote totale d'environ 2.00. Objectif affiché dans `Claude.md` : 70-80 % de réussite sur ces combinés. Phase de test, pas de mises réelles déclarées.

**Stack.** PHP 8.2 / Laravel 12, Blade + Tailwind + Alpine.js, MariaDB (`.env`), cache/queue/session en base. Dépendances externes : API-Football (fixtures, stats, cotes), The Odds API (cotes multi-bookmakers, tracking CLV), OpenWeatherMap, API Anthropic (Claude). Aucune bibliothèque de stats/ML : tout est codé à la main en PHP.

**Lancement.** `composer install`, `npm install && npm run build`, `.env` avec `DB_*`, `API_FOOTBALL_KEY`, `ODDS_API_KEY`, `ANTHROPIC_API_KEY`, `OPENWEATHERMAP_KEY`, puis `php artisan migrate` et `php artisan serve`. Le scheduler est désactivé (`routes/console.php:12-16`) ; le workflow quotidien est manuel : `php artisan pipeline:run-sync YYYY-MM-DD` → clic "Analyser" sur `/analysis` (ou POST `/analysis/analyze-match/{id}`) → `php artisan ai:batch --date=` → `php artisan combos:generate` → lendemain `php artisan results:collect --date=`.

**Arborescence utile.**

| Dossier / fichier | Rôle |
|---|---|
| `app/Http/Controllers/MatchAnalysisController.php` | Orchestration web : liste, analyse d'un match, saisie manuelle, historique, dashboard (1635 lignes, dont ~700 de code mort) |
| `app/Services/AnalyzerService.php` | "Layer 1" : consensus entre sources, confiance pondérée, score, niveaux |
| `app/Services/Probability/` | `XGModelService` (estimation des λ) + `PoissonModelService` (matrice de scores) = "Source D" |
| `app/Services/Betting/` | `CalculatorService` (pondération), `RulesService` (règles, non branché), `Layer2Service` + `Analyzers/` (H2H, tactique ; forme/blessures/xG non branchés), `ComboSelectorService`, `FormationProfiles`, `Layer2Coefficients` |
| `app/Services/ValueAnalyzer.php` | Edge vs cote, Kelly (non utilisé pour miser) |
| `app/Services/AI/` | `ClaudeClient` + 5 agents : `MatchAnalystService`, `ValueHunterService`, `RiskKillerService`, `FinalJudgeService`, `ComboBuilderService` |
| `app/Services/DataPipeline/MatchEnricherService.php` | Normalisation API-Football → tables `matches` / `advanced_data` |
| `app/Services/Context/ContextEnricherService.php` | Fatigue, enjeu, météo, arbitre, pression coach |
| `app/Services/Api/` | Clients HTTP `ApiFootballService`, `OddsApiService`, `WeatherService` |
| `app/Services/Market/CLVTrackerService.php` | Snapshots de cotes, CLV, "sharp money" |
| `app/Services/Backtesting/BacktestEngine.php` | Backtest Poisson sur matchs terminés |
| `app/Jobs/` | `FetchMatchDataJob`, `FetchOddsJob`, `GenerateDailyCombosJob` |
| `app/Console/Commands/` | `ai:batch`, `results:collect`, `context:enrich`, `market:track`, `pipeline:backfill`, `app:reset`, `import:react-history` + 6 commandes `test:*`/`*:test` (scripts manuels) |
| `config/reliability.php`, `config/analyzer.php` | Fiabilités par source/marché, seuils, marchés analysés |
| `config/api-football.php`, `config/odds-api.php` | Ligues suivies, mappings, TTL cache, quotas |
| `database/migrations/` | 26 migrations, 13 tables métier |
| `storage/app/private/reports/` | 21 rapports JSON (`ai_batch_*`, `ai_results_*`) du 2026-04-25 au 2026-05-05 |
| `Claude.md`, `Note.txt` | Roadmap et notes de conception (source des "calibrations" citées plus bas) |
| `tests/` | Uniquement les tests Breeze (auth/profil) ; zéro test métier |

---

## 2. Pipeline de bout en bout

Il y a deux chemins d'entrée qui aboutissent au même moteur. Le chemin automatique est celui réellement utilisé (le manuel est l'héritage d'une app React antérieure).

### 2.1 Chemin automatique (pipeline API)

**Étape A — Import des matchs.** `php artisan pipeline:run-sync {date}` (`routes/console.php:33-41`) → `FetchMatchDataJob::dispatchSync($date, null, true, $all)`.
`app/Jobs/FetchMatchDataJob.php:41-160` :
1. Retire les ligues inactives (`config/api-football.php:48-52`).
2. `updatePreviousDayResults()` (l.170-208) : si des matchs de J-1 ne sont pas `completed`, recharge les fixtures de J-1 et met à jour les scores FT/AET/PEN via `MatchEnricherService::upsertFromApiFootball`.
3. `ApiFootballService::getFixturesByDate($date)` puis filtre sur `config('api-football.leagues')` puis **filtre horaire conditionnel** `PIPELINE_MATCH_START_HOUR`/`END_HOUR` (UTC, `.env` : 12-21) sauf option `--all`.
4. Pour chaque fixture : `MatchEnricherService::upsertFromApiFootball()` (crée/maj la ligne `matches`) → `ApiFootballService::getFullMatchData($fixtureId)` (7 appels : fixture, H2H, stats domicile, stats extérieur, blessures, prédictions API-Football, lineups, classement ; `ApiFootballService.php:400-435`) → `MatchEnricherService::enrichWithAdvancedData()` (écrit `advanced_data`) → `ApiFootballService::getFixtureOdds()` (endpoint `/odds`, bookmaker 8 = Bet365 en priorité, fallback tous bookmakers avec **cote max** par marché, `ApiFootballService.php:253-398`) → `enrichWithApiFootballOdds()` (écrit 16 colonnes de cotes).
5. Chaîne `FetchOddsJob` (`app/Jobs/FetchOddsJob.php`) : appelle The Odds API par ligue, apparie les équipes par similarité de nom, puis **`enrichWithOdds()` réécrit `odds_home/draw/away/over_2_5/under_2_5/btts/dc` avec la meilleure cote parmi ~23 bookmakers** (`MatchEnricherService.php:141-174`, `OddsApiService.php:341-492`). Ce comportement contredit la doc interne qui affirme que The Odds API ne sert plus qu'au CLV.

**Étape B — Analyse d'un match.** Depuis `/analysis`, bouton "Analyser" → `POST /analysis/analyze-match/{match}` → `MatchAnalysisController::analyzeExistingMatch()` (`MatchAnalysisController.php:99-197`) :
1. **Conditionnel** : si `context_data.weather` ou `context_data.fatigue` est vide, appelle `ContextEnricherService::enrich($match)` (sans `$fullMatchData`, donc arbitre jamais disponible, cf. §8) et sauvegarde dans `advanced_data.context_data`.
2. `AnalyzerService::analyze($match)` (`AnalyzerService.php:30-70`) :
   - `generateSourceD()` → `XGModelService::predict()` → `PoissonModelService::fullAnalysis()` → picks + confiances pour 5 marchés.
   - `generateSourceE()` → transforme `context_data.comparison` et `apiFootballAdvice` (prédictions API-Football) en picks 1X2, DC, éventuellement O/U.
   - `extractManualPredictions()` → sources A/B/C si présentes en base (jamais dans le chemin automatique).
   - Pour chaque marché de `config('analyzer.markets')` (`winner, overUnder, btts, doubleChance, exactScore`) : `analyzeMarket()` → `detectConsensus()` → `getBetFromConsensus()` → `CalculatorService::calculateWeightedConfidence()` → `CalculatorService::calculateScore()` → `estimateOdds()`.
   - `applyValueAnalysis()` via `ValueAnalyzer::analyzeRecommendation()` (bonus/malus sur le score).
   - Tri par score, `assignLevels()` (1 à 4), `calculateGlobalConfidence()` = moyenne simple des confiances des 5 marchés.
3. **Conditionnel** : si `advanced_data` a `tactical_data` ou `sofascore_data` ou `footystats_data` → `Layer2Service::analyzeAdvanced()` (`Layer2Service.php:29-118`) : dimension H2H (si `sofascore_data.h2h`), tactique (si formations), contexte (toujours). `globalScore = 0.6 × L1 + 0.4 × L2 + bonus convergence`. Les recommandations "enrichies" produites par Layer 2 **ne sont pas persistées** ; seul `global_confidence`, `layer2_score`, `convergence` le sont.
4. Sauvegarde : `matches.global_confidence/layer1_score/layer2_score/convergence/context`, suppression puis recréation des lignes `recommendations` (sans les champs `value_*`, cf. §8).

**Étape C — Agents IA.** `php artisan ai:batch --date=` (`app/Console/Commands/AiBatchAnalyze.php`). Pour chaque match du jour ayant `global_confidence` non nul, des recommandations et `odds_home > 0` (l.39-45), dans cet ordre, chacun avec cache 6 h :
1. `MatchAnalystService::analyze()` (modèle codé en dur `claude-sonnet-4-6`, l.21) : recalcule `XGModelService::predict()`, construit un prompt avec λ, P(Under 2.5), cotes, forme, blessures, H2H, enjeux, météo, pression → verdict `CONFIRMS_UNDER | DOUBTS_UNDER | CONTRADICTS_UNDER`.
2. `ValueHunterService::analyze()` : prompt avec cotes, probabilités implicites et les picks/confiances Layer 1 → liste de `value_bets` avec `value_margin`.
3. `RiskKillerService::analyze()` : prompt "avocat du diable" → `risk_score` 0-100.
4. `FinalJudgeService::decide()` (`FinalJudgeService.php:49-111`) : règles dures PHP (`applyHardRules`, l.226-231) + décision Under 2.5 entièrement en PHP (`decideUnderMarket`, l.125-221) + appel Claude pour `decision_global`, dont la sortie est écrasée si elle contredit la règle dure (l.91-98).
5. `AIAnalysis::updateOrCreate()` puis rapport `storage/app/private/reports/ai_batch_{date}.json`.

**Étape D — Combinés.** `php artisan combos:generate {date}` → `ComboSelectorService::generateForDate()` (`ComboSelectorService.php:62-142`) :
1. `getEligibleMatchesWithCandidates()` : matchs analysés du jour, ligue dans `COMBO_ALLOWED_LEAGUES` (l.26-38), candidats par match selon `decision_under`/`decision_global` de l'IA (`buildCandidatesForMatch`, l.187-383).
2. **Conditionnel** : si moins de `COMBO_MIN_MATCHES` (4) matchs éligibles → aucun combo, anciens combos du jour supprimés.
3. Produit cartésien des candidats sur toutes les combinaisons de 4-5 matchs de ligues distinctes, filtre cote totale dans [1.85 ; 2.20], scoring, top 3 sauvegardé (`daily_combos` rank 1-3).
4. `ComboBuilderService::build()` (Claude) → combo IA sauvegardé en rank 0 si recommandation ≠ AVOID et taille dans [min, max].

**Étape E — Résultats.** `php artisan results:collect --date=` (`CollectResults.php`) : recharge les scores si besoin, puis pour **chaque match analysé par l'IA** (quelle que soit la décision) calcule `GAGNE` si total buts ≤ 2, `PERDU` sinon, et agrège le taux de réussite par `decision_under` BET/LEAN. Écrit `ai_results_{date}.json`. Rien n'est écrit en base (`ai_vs_real_result`, `daily_combos.won`, `recommendations.validated` restent nuls).

### 2.2 Chemin manuel (hérité)

`GET /analysis/manual` → formulaire 5 étapes → `fetch('/analysis', {matches:[…]})` → `MatchAnalysisController::analyze()` (l.1136-1219) : construit un `FootballMatch` non persisté + 3 `TempSource` (A/B/C toujours présents, cf. §3.3), `AnalyzerService::analyze()`, `ValueAnalyzer`, puis Layer 2 si données tactiques/sofascore/footystats → résultat en session → `/analysis/results` → `POST /analysis/save` persiste (avec des bugs de double encodage JSON, cf. §8).

### 2.3 Ce qui n'est traversé par aucun chemin

`RulesService` (règles spéciales Source B/C), `FormAnalyzer`, `InjuryAnalyzer`, `XGAnalyzer`, `ContextAnalyzer`, `ParserService`, `DataImportService`, les méthodes `statistics/importData/exportJson*/exportCsv/saveAll/apiAnalyze` du contrôleur, `routes/api.php` (non enregistré dans `bootstrap/app.php`), `GenerateDailyCombosJob` (jamais dispatché), la table `referees` (créée avec des valeurs par défaut, jamais enrichie).

---

## 3. Format d'entrée

Le système n'accepte pas de fichier d'entrée. Il a deux portes : (a) l'API-Football, dont les payloads sont normalisés dans deux tables ; (b) un JSON POSTé par le formulaire manuel. Le moteur (`AnalyzerService`, `XGModelService`, `Layer2Service`) lit uniquement ces structures normalisées. La section 3.2 décrit la structure interne réellement consommée, la 3.3 le JSON manuel, la 3.4 donne un exemple complet.

### 3.1 Table `matches` (ligne par match) — champs lus par le moteur

| Colonne | Type / unité | Obligatoire pour le moteur ? | Producteur | Si absent |
|---|---|---|---|---|
| `home_team`, `away_team` | string | oui | API ou formulaire | erreur SQL (NOT NULL) |
| `home_team_id`, `away_team_id` | int (id API-Football) | non | API | fatigue/enjeu/blessures indisponibles (`ContextEnricherService.php:83-85`) |
| `match_date` | datetime UTC | oui | API (`fixture.date`) ou formulaire | erreur SQL |
| `competition` | string | oui | API (`league.name`) | erreur SQL ; météo : pays déduit du nom (`guessCountry`, 5 pays seulement) |
| `league_id` | int API-Football | non | API | moyenne xG ligue = 1.40 par défaut ; exclu des combos (whitelist) |
| `odds_home`, `odds_draw`, `odds_away` | decimal(6,3), cote décimale européenne | **de fait oui** | API-Football /odds puis The Odds API (écrase) | `odds_home <= 0` et pas d'`advanced_data` → Source D nulle ; sinon Source D sans signal marché (λ = moyenne ligue). `ai:batch` ignore le match (`odds_home > 0`) |
| `odds_over_2_5`, `odds_under_2_5` | decimal | non | idem | pas de calibration du total de buts (phase 2 de `lambdasFromOdds`), verdict value `NO_ODDS` sur O/U |
| `odds_over_1_5/3_5/4_5`, `odds_under_1_5/3_5/4_5` | decimal | non | API-Football uniquement | Under 3.5 / Over 1.5 absents des candidats combo |
| `odds_btts_yes/no`, `odds_dc_1x/12/x2` | decimal | non | API | `NO_ODDS` sur ces marchés, candidats DC/BTTS absents |
| `odds_over_2_0/2_25`, `odds_under_2_0/2_25` | decimal | non | The Odds API | inutilisés (colonnes mortes) |
| `odds_home_over_0_5` … `odds_away_under_1_5` | decimal | non | formulaire manuel seulement | inutilisés par le moteur |
| `score_home`, `score_away`, `completed` | int, bool | non | pipeline J-1, `results:collect` | résultats "EN ATTENTE" |
| `data_source` | enum `manual|api` | oui | pipeline = `api` | la page `/analysis` ne liste que `api` |
| `odds_at_pred_*`, `odds_closing_*`, `odds_api_event_id` | decimal / string | non | `market:track` | CLV indisponible, bonus CLV combo jamais appliqué |

### 3.2 Table `advanced_data` (une ligne JSON par match) — clés réellement consommées

Colonnes JSON : `tactical_data`, `sofascore_data`, `footystats_data`, `fbref_data`, `context_data` (+ colonnes de sortie `dimensions`, `tactical_insights`, `original_recommendations`, `enriched_recommendations`, `detailed_explanation`, remplies seulement par le chemin manuel).

**`sofascore_data`** (produit par `MatchEnricherService::enrichWithAdvancedData`, l.184-248) :

| Clé | Format produit par le pipeline | Consommateur | Remarque |
|---|---|---|---|
| `h2h` | **liste** de ≤10 objets `{date, home, away, homeGoals, awayGoals, winner:'home|away|draw'}` (l.257-272) | `Layer2Service` → `H2HAnalyzer` attend un **objet** `{totalGames, homeWins, awayWins, atHomeAdvantage, lastMatches[]}` (`H2HAnalyzer.php:26-30`) | Incompatibilité : score H2H toujours 50, confiance 0 (§8). `MatchAnalystService` lit correctement la liste. |
| `injuries` | `{home:[{player, type, reason}], away:[…]}` (l.277-297) | `XGModelService::injuryModifier` (compte de joueurs uniques ×3 %), prompts Risk Killer / Match Analyst | Doublons connus, dédupliqués par nom |
| `recentForm` | `{home:['W','D','L',…], away:[…]}` (chaîne `form` d'API-Football découpée, l.302-328) | `ContextEnricherService::calculatePressure` | `MatchAnalystService::summarizeForm` attend des objets `{result, goalsFor…}` → affiche `????? (0 BF, 0 BC)` au LLM |

**`footystats_data`** : `{expectedGoals:{home:{xGFor, xGAgainst}, away:{…}}}` où `xGFor = pourcentage_victoire_API-Football × 0.03` (l.355-377). Ce n'est pas un xG. Lu par `XGModelService::lambdasFromXGProxy` (poids 20 %). Si absent : signal ignoré, poids renormalisés.

**`context_data`** : produit en deux temps.
- Par le pipeline (l.333-350) : `importance` ('medium', ou 'high' si le conseil API contient "combo" ou "draw"), `reason`, `apiFootballAdvice` (texte), `comparison` (objet API-Football avec `form, att, def, poisson_distribution, h2h, goals, total`, chacun `{home:'56%', away:'44%'}`). Consommé par Source E (`AnalyzerService.php:91-136`) et par `XGModelService::lambdasFromComparison` (poids 30 %). Si `comparison` absent : Source E nulle, signal ignoré.
- Par `ContextEnricherService::enrich()` (l.34-68) : `fatigue{available, home{matches_7d,14d,21d,european,fatigue_score}, away{…}, advantage}`, `stakes{available, home{stake,motivation,rank,description}, away{…}, motivation_delta}`, `weather{condition, temperature (°C), wind_speed (km/h), rain, snow, impact, over_modifier, btts_modifier, description}`, `referee{available,…}` (toujours `available:false` dans le flux réel), `coachPressure{available, home{score, consecutive_losses,…}, away{…}}`, `importance` recalculée. Consommé par `Layer2Service` (contexte) et les prompts IA. Tout est optionnel avec repli neutre.

**`tactical_data`** : `{home:{formation:'4-3-3', style}, away:{…}}` uniquement si API-Football renvoie des lineups (rarement disponible à 14 h UTC). Consommé par `TacticalAnalyzer`. Absent → dimension tactique ignorée.

**`fbref_data`** : `{league:{home:{rank, points, played, win, draw, lose, goalsFor, goalsAgainst, form}, away:{…}}}` depuis le classement. Consommé par `analyzeStakes` (repli). Absent → `stakes.available=false`.

**Table `sources`** (A/B/C manuelles) : `{match_id, source_type:'A'|'B'|'C', predictions: JSON}` avec `predictions = {winner:{pick,confidence} | {home,draw,away} pour C, overUnder:{pick,confidence}, btts:{…}, doubleChance:{…}, exactScore:{…}}`. Jamais alimentée par le pipeline.

### 3.3 JSON du formulaire manuel (`POST /analysis`, corps `{"matches":[…]}`)

Lu par `MatchAnalysisController::analyze()` (l.1136-1219). Accès direct sans validation : une clé obligatoire manquante lève une exception attrapée et renvoie "Erreur lors de l'analyse".

| Champ | Type | Obligatoire | Si absent / défaut |
|---|---|---|---|
| `teams.home`, `teams.away` | string | oui | exception |
| `date` | string `YYYY-MM-DDTHH:MM` | oui | exception |
| `competition` | string | oui (lu sans `??`) | exception |
| `odds.home`, `odds.draw`, `odds.away` | float (cote décimale) | oui | exception ; `0` accepté mais désactive le signal marché |
| `odds.over_2_5`, `under_2_5`, `btts_yes`, `btts_no`, `dc_1x`, `dc_12`, `dc_x2`, `home_over_0_5` … `away_under_1_5` | float ou null | non | null |
| `sources.sourceA` | objet `{winner:{pick:'1'|'X'|'2', confidence:int}, overUnder:{pick:'Over'|'Under'}, btts:{pick:'Yes'|'No'}, doubleChance:{pick:'1X'|'12'|'X2'}, exactScore:{pick:'2-0'}}` | **oui** (type `array` imposé, `createTempSource` l.1631) | TypeError si null. Le JS envoie toujours des valeurs par défaut (`'1'`, 50, `'Under'`, `'No'`, `'1X'`, `'2-0'`) même si l'utilisateur n'a rien saisi (`manual-input.blade.php`, `collectSources`). Confiance de A forcée à 50 dans le moteur. |
| `sources.sourceB` | idem avec `confidence` sur chaque marché | oui | défauts JS : `'1'`/67, `'Under'`/63, `'No'`/64, `'1X'`/75, `'1:0'`/18 |
| `sources.sourceC` | `winner:{home,draw,away}` en % + autres marchés `{pick,confidence}` | oui | défauts JS : 45/30/25, `'Under'`/60, `'No'`/58, `'1X'`/75 |
| `tacticalData` | `{home:{formation, style}, away:{…}}` ou null | non | Layer 2 tactique ignorée |
| `contextData` | `{importance:'low|medium|high|critical', reason, restDays:{home:int, away:int}}` | non | contexte neutre (le JS envoie `medium`, 7, 7) |
| `sofascoreData` | `{recentForm:{home:{streak,W,D,L},away}, injuries:{home:[{name,position,importance:'key|regular|rotation',reason}],away}, h2h:{totalGames,homeWins,draws,awayWins,lastMatches:[{date,score,location:'home|away|neutral',winner}]}, matchStats, seasonStats}` ou null | non | seul `h2h` est réellement exploité (Layer 2). `recentForm` et `injuries` ne sont lus par personne dans ce chemin. |
| `footyStatsData` | `{expectedGoals:{home:{xGFor,xGAgainst},away}, overUnder, btts, series}` ou null | non | **inutilisé** : `XGModelService` lit `$match->advancedData`, qui n'existe pas pour un match non persisté. Seule sa présence déclenche Layer 2. |
| `fbrefData` | `{league:{home:{position,points},away}, topPlayers}` ou null | non | inutilisé |

### 3.4 Exemple complet et valide (formulaire manuel)

```json
{
  "matches": [
    {
      "teams": { "home": "Olympique de Marseille", "away": "Paris Saint Germain" },
      "date": "2026-09-20T19:45",
      "competition": "Ligue 1",
      "odds": {
        "home": 3.40, "draw": 3.60, "away": 2.05,
        "over_2_5": 1.72, "under_2_5": 2.10,
        "btts_yes": 1.62, "btts_no": 2.25,
        "dc_1x": 1.75, "dc_12": 1.28, "dc_x2": 1.30,
        "home_over_0_5": 1.18, "home_under_0_5": 4.60,
        "home_over_1_5": 1.95, "home_under_1_5": 1.85,
        "away_over_0_5": 1.12, "away_under_0_5": 5.80,
        "away_over_1_5": 1.65, "away_under_1_5": 2.20
      },
      "sources": {
        "sourceA": {
          "winner": { "pick": "2", "confidence": 55 },
          "overUnder": { "pick": "Over" },
          "btts": { "pick": "Yes" },
          "doubleChance": { "pick": "X2" },
          "exactScore": { "pick": "1-2" }
        },
        "sourceB": {
          "winner": { "pick": "2", "confidence": 61 },
          "overUnder": { "pick": "Over", "confidence": 64 },
          "btts": { "pick": "Yes", "confidence": 66 },
          "doubleChance": { "pick": "X2", "confidence": 78 },
          "exactScore": { "pick": "1:2", "confidence": 14 }
        },
        "sourceC": {
          "winner": { "home": 30, "draw": 27, "away": 43 },
          "overUnder": { "pick": "Over", "confidence": 58 },
          "btts": { "pick": "Yes", "confidence": 60 },
          "doubleChance": { "pick": "X2", "confidence": 72 },
          "exactScore": { "pick": "1:2", "confidence": 12 }
        }
      },
      "tacticalData": {
        "home": { "formation": "4-2-3-1", "style": "balanced" },
        "away": { "formation": "4-3-3", "style": "attacking" }
      },
      "contextData": {
        "importance": "high",
        "reason": "Classique, course au titre",
        "restDays": { "home": 6, "away": 3 }
      },
      "sofascoreData": {
        "recentForm": {
          "home": { "streak": "WWDWL", "W": 3, "D": 1, "L": 1 },
          "away": { "streak": "WWWDW", "W": 4, "D": 1, "L": 0 }
        },
        "injuries": {
          "home": [ { "name": "Amine Harit", "position": "AM", "importance": "regular", "reason": "injury" } ],
          "away": [ { "name": "Presnel Kimpembe", "position": "CB", "importance": "key", "reason": "injury" } ]
        },
        "h2h": {
          "totalGames": 6, "homeWins": 1, "draws": 1, "awayWins": 4,
          "lastMatches": [
            { "date": "2026-03-16", "score": "1-3", "location": "away", "winner": "away" },
            { "date": "2025-10-27", "score": "0-2", "location": "home", "winner": "away" },
            { "date": "2025-03-16", "score": "1-3", "location": "away", "winner": "away" }
          ]
        }
      },
      "footyStatsData": {
        "expectedGoals": {
          "home": { "xGFor": 1.55, "xGAgainst": 1.10 },
          "away": { "xGFor": 2.05, "xGAgainst": 0.85 }
        }
      },
      "fbrefData": {
        "league": { "home": { "position": 3, "points": 12 }, "away": { "position": 1, "points": 15 } }
      }
    }
  ]
}
```

Exemple minimal de l'enregistrement interne consommé par le chemin automatique (une ligne `matches` + une ligne `advanced_data`) :

```json
{
  "matches": {
    "api_football_id": 1234567, "home_team": "Olympique de Marseille", "home_team_id": 81,
    "away_team": "Paris Saint Germain", "away_team_id": 85, "match_date": "2026-09-20 19:45:00",
    "competition": "Ligue 1", "league_id": 61, "season": 2026, "data_source": "api",
    "odds_home": 3.40, "odds_draw": 3.60, "odds_away": 2.05,
    "odds_over_2_5": 1.72, "odds_under_2_5": 2.10, "odds_over_3_5": 2.75, "odds_under_3_5": 1.42,
    "odds_over_1_5": 1.22, "odds_under_1_5": 4.10, "odds_btts_yes": 1.62, "odds_btts_no": 2.25,
    "odds_dc_1x": 1.75, "odds_dc_12": 1.28, "odds_dc_x2": 1.30
  },
  "advanced_data": {
    "sofascore_data": {
      "h2h": [ { "date": "2026-03-16T20:45:00+00:00", "home": "Paris Saint Germain", "away": "Olympique de Marseille", "homeGoals": 3, "awayGoals": 1, "winner": "home" } ],
      "injuries": { "home": [ { "player": "A. Harit", "type": "Missing Fixture", "reason": "Knee Injury" } ], "away": [] },
      "recentForm": { "home": ["W","W","D","W","L"], "away": ["W","W","W","D","W"] }
    },
    "footystats_data": { "expectedGoals": { "home": { "xGFor": 0.90, "xGAgainst": 1.35 }, "away": { "xGFor": 1.35, "xGAgainst": 0.90 } } },
    "context_data": {
      "importance": "critical", "apiFootballAdvice": "Combo Double chance : Paris Saint Germain or draw and +1.5 goals",
      "comparison": { "form": { "home": "45%", "away": "55%" }, "att": { "home": "42%", "away": "58%" }, "def": { "home": "48%", "away": "52%" }, "poisson_distribution": { "home": "40%", "away": "60%" }, "h2h": { "home": "30%", "away": "70%" }, "goals": { "home": "44%", "away": "56%" }, "total": { "home": "41.5%", "away": "58.5%" } },
      "fatigue": { "available": true, "home": { "matches_7d": 1, "matches_14d": 3, "matches_21d": 4, "european": false, "fatigue_score": 20 }, "away": { "matches_7d": 2, "matches_14d": 4, "matches_21d": 6, "european": true, "fatigue_score": 70 }, "advantage": 50 },
      "stakes": { "available": true, "home": { "stake": "champions_league", "motivation": 85, "rank": 3 }, "away": { "stake": "title", "motivation": 95, "rank": 1 }, "motivation_delta": -10 },
      "weather": { "condition": "Clear", "temperature": 19, "wind_speed": 14, "rain": false, "snow": false, "impact": "none", "over_modifier": 0, "btts_modifier": 0 },
      "referee": { "available": false, "reason": "Arbitre non assigne" },
      "coachPressure": { "available": true, "home": { "score": 0, "consecutive_losses": 1, "style_impact": "neutral" }, "away": { "score": 0, "consecutive_losses": 0, "style_impact": "neutral" } }
    }
  }
}
```

---

## 4. Logique de prédiction

C'est un **mélange de modèle statistique simple (Poisson), de pondérations codées à la main et de règles métier, complété par des LLM**. Il n'y a **aucun modèle entraîné** : pas d'apprentissage, pas d'ajustement automatique de paramètres, pas de fichier de poids. Toutes les valeurs numériques ci-dessous sont des constantes PHP.

### 4.1 Source D : modèle Poisson maison (`XGModelService` → `PoissonModelService`)

**Étape 1 — estimer λ_home et λ_away (buts attendus) depuis 4 signaux** (`XGModelService.php:126-146`).

| Signal | Poids | Calcul | Constantes | Emplacement |
|---|---|---|---|---|
| `market` (cotes 1X2) | 0.40 | Probabilités implicites normalisées (marge retirée), puis grid search λ ∈ [0.3;3.5]×[0.2;3.0] pas 0.05 minimisant l'erreur L1 sur 1X2 Poisson ; puis si cotes O/U 2.5 : bissection sur le total λ pour égaler P(Under 2.5) implicite | bornes de grille, 40 itérations, tolérance 0.002 | l.229-322 |
| `comparison` (API-Football) | 0.30 | `force = 0.30·att + 0.30·poisson + 0.20·form + 0.20·goals` (en % domicile) ; `λ_home = moyLigue × force/50`, `λ_away = moyLigue × (100−force)/50`, puis modulation par `def` : `×(0.7 + defMod×0.6)` | 0.30/0.30/0.20/0.20, 0.7, 0.6 | l.184-216 |
| `xg_proxy` | 0.20 | `xGFor` de `footystats_data` = `% victoire API × 0.03`, remplacé par la moyenne ligue si < 0.5 | `XG_PROXY_FLOOR = 0.5` | l.157-175 ; `MatchEnricherService.php:362` |
| `injuries` | multiplicatif | `1 − 0.03 × nb_blessés_uniques`, plancher 0.7 | 0.03, 0.7 | l.331-356 |

Fusion = moyenne pondérée des signaux présents (poids renormalisés). Avec les 3 signaux présents, le marché pèse en réalité 44 %.

**Étape 2 — avantage domicile** (l.412-421) : `λ_home × 1.08`, `λ_away × 0.952` (facteurs `HOME_ADVANTAGE = 1.20` et `AWAY_FACTOR = 0.88` atténués à 40 % "parce que le marché l'intègre déjà" ; le commentaire parle de 35 % de poids marché, le code de 40 %). Appliqué **aussi** à la composante marché, donc double comptage partiel.

**Étape 3 — clamp** : λ_home ∈ [0.3;3.5], λ_away ∈ [0.2;3.0] (l.89-91).

**Moyennes xG par ligue** (`LEAGUE_AVG_XG_BY_ID`, l.42-55) : 39→1.55, 78→1.60, 135→1.40, 140→1.45, 61→1.40, 88→1.55, 40→1.45, 136→1.30, 62→1.30, 144→1.45, 203→1.50 ; défaut 1.40. Commentaire : "calibration basée sur les moyennes historiques" ; aucune donnée ni script ne le prouve.

**Étape 4 — Poisson** (`PoissonModelService`) : matrice 7×7 (0 à 6 buts, `MAX_GOALS = 6`), Poisson indépendantes (pas de correction Dixon-Coles malgré la doc), 1X2 renormalisé, Over/Under 2.5 = masse ≤ 2 buts, BTTS = (1−e^−λh)(1−e^−λa), Double Chance = sommes, top 5 scores exacts.

**Étape 5 — picks Source D** (l.434-485) : pour chaque marché, l'issue la plus probable, `confidence = probabilité en %` (entier). Le score exact prend le score le plus probable (typiquement 8-13 %).

### 4.2 Source E : prédictions API-Football (`AnalyzerService.php:91-136`)

- 1X2 : `home = comparison.total.home`, `away = 100 − home`, `draw = max(15, 35 − |home − away|)`, puis répartition du reste. Formule inventée, non justifiée.
- Double Chance : somme des deux meilleures.
- O/U : parsing du texte `apiFootballAdvice` : contient "goals" ou "over" → `Over` 55 % ; sinon "under"/"-2.5"/"-3.5" → `Under` 55 %. Bug : "… and -3.5 goals" (signal Under) est classé Over car "goals" est testé en premier.

### 4.3 Sources A/B/C (saisie manuelle, `config/reliability.php`)

Sites externes (good-sport, mybets, "probabilities") saisis à la main. Fiabilités déclarées "empiriques sur 33 matchs validés" (décembre 2025) : A DC 0.86 / BTTS 0.63 / 1X2 0.60 / O/U 0.61 ; B DC 0.90 / O/U 0.68 / BTTS 0.67 / 1X2 0.59 / score 0.12 ; C DC 0.88 / 1X2 0.68 / BTTS 0.66 / O/U 0.64. Confiance de A forcée à 50 (`AnalyzerService.php:153`). Absentes du chemin automatique.

### 4.4 Layer 1 : consensus, confiance, score (`AnalyzerService`, `CalculatorService`)

1. **Consensus** (l.226-244) : toutes les sources d'accord → `TOTAL` ; ≥2 d'accord → `MAJORITÉ` ; sinon `CONFLIT`. En pratique (D + E seulement) : soit TOTAL, soit CONFLIT ; MAJORITÉ est impossible. En conflit, priorité fixe D > E > B > C > A (l.259).
2. **Confiance pondérée** (`CalculatorService.php:21-36`) : `Σ conf_i × fiab_i / Σ fiab_i` avec fiabilités de `config/reliability.php:32-80` :

| Source | DC | 1X2 | O/U | BTTS | Score exact |
|---|---|---|---|---|---|
| D (Poisson) | 0.92 | 0.80 | 0.75 | 0.70 | 0.15 |
| E (API-Football) | 0.82 | 0.65 | 0.55 | 0.50 | 0.05 |

Ces valeurs pour D/E sont **choisies à la main** ; le commentaire justifie D par "écart 3.2 % vs marché", ce qui mesure la proximité avec le bookmaker, pas la justesse prédictive.

3. **Score de priorité** (`CalculatorService.php:41-69`) : `+25` si TOTAL, `+22` si MAJORITÉ, `+ confiance × 0.25`, `+10` "cohérence" (**toujours vrai**, `AnalyzerService.php:219`), `+ fiabilité moyenne du marché sur les 5 sources × 15`. `specialRule` toujours null : `RulesService` n'est jamais appelé.
4. **Value** (`ValueAnalyzer`, appliqué l.335-362) : `edge = confiance/100 × cote − 1`. Edge ≥ 5 % → `VALUE`, score `+min(edge, 15)` ; edge > 30 % → `SUSPICIOUS`, score −10 ; sinon `NO_VALUE`, score −5 ; pas de cote → `NO_ODDS`. Kelly 1/4 sur bankroll fictive 1000 calculé mais jamais utilisé. La "probabilité réelle" injectée est la **confiance pondérée**, qui n'est pas une probabilité calibrée.
5. **Niveaux** (l.316-323) : score ≥ 85 → 1, ≥ 70 → 2, ≥ 55 → 3, sinon 4 ("VALUE BET" en libellé).
6. **Confiance globale** (l.325-329) : **moyenne simple des confiances des 5 marchés, score exact inclus** (~10 %). Résultat observé dans les rapports : médiane 54, min 47, max 58 sur 235 analyses. Cette valeur pilote ensuite les seuils IA (≥ 57 pour un BET Under) et est affichée comme "confiance" du match.
7. **Contexte** (l.368-387) : cote < 1.50 → "favori clair" ; |cote_home − cote_away| < 0.5 → "équilibré". Sert uniquement au texte `logic`.

### 4.5 Layer 2 (`Layer2Service`, `Layer2Coefficients`)

- Dimensions réellement calculées : `h2h` (poids 0.08), `tactical` (0.15), `context` (0.07). Les poids `injuries` 0.28, `form` 0.22, `xg` 0.18, `league_position` 0.02 déclarés dans `GLOBAL_WEIGHTS` (l.159-167) correspondent à des analyseurs **jamais appelés**.
- `context` : `50 + fatigue.advantage × 0.15 + motivation_delta × 0.10`, confiance fixe 80 (l.68-93).
- `tactical` : `50 + 1.5 × (offensif_home − défensif_away) + 1.2 × (défensif_home − offensif_away)` depuis `FormationProfiles` (valeurs à la main par formation), confiance fixe 85. Lineups absents → dimension absente ; lineup inconnu → profil par défaut.
- `h2h` : `50 + bonus dominance (±20/10/5) + moyenne pondérée des derniers matchs (±10, ×0.3 si à l'extérieur, décroissance 1.0/0.8/0.5/0.3 sur 6 mois/1 an/2 ans/3 ans)`. **Toujours 50 dans le flux API** (format incompatible, §8).
- Fusion : `global = 0.6 × L1 + 0.4 × L2 + bonus` avec bonus +3 si |L1 − L2| ≤ 5, 0 si ≤ 15, −5 sinon (l.185-195). Comme L2 ≈ 50, la fusion tire la confiance globale vers 50.
- Ajustements par recommandation (l.147-204 : H2H ±6 sur 1X2, tactique ±3, météo ×0.3, borné ±20) : calculés mais **non sauvegardés** dans le flux API.

### 4.6 Contexte enrichi (`ContextEnricherService`, `WeatherService`)

Toutes les valeurs sont codées à la main : fatigue (7 j : 1/2/3+ matchs → +10/+25/+40 ; 14 j : 3/4/5+ → +10/+20/+30 ; 21 j : 6/7+ → +10/+20 ; coupe d'Europe +15 ; l.158-177 et 118-119), enjeux (titre 95, C1 85, Europe 75, maintien 90, danger 80, milieu 55 ; l.226-260), pression coach (défaites consécutives 2/3/4/5 → +15/+30/+45/+60 ; l.454-507), météo (vent > 50 km/h : Over −15/BTTS −10 ; > 40 : −10/−5 ; > 30 : −5 ; pluie −5 ; neige −10/−8 ; > 35 °C +5 ; < 0 °C −8 ; < 2 °C −3 ; `WeatherService.php:51-138`), arbitre (défauts 4.0 jaunes, 0.15 rouges, 0.25 pen, style `moderate` ; jamais mis à jour).

### 4.7 Couche IA (5 agents Claude)

- Modèles : `.env` → `claude-sonnet-4-20250514` pour Value Hunter, Risk Killer, Final Judge ; `claude-sonnet-4-6` codé en dur pour Match Analyst et Combo Builder. Max tokens 1000 / 800 / 1500. Cache 6 h par match, non contourné par `--force`.
- **Value Hunter** : reçoit cotes, probabilités implicites et les picks Layer 1 avec leur confiance ; doit renvoyer `value_margin = model_probability − implied_probability` (> 3 %) et `overall_value_score`. Le LLM fait l'arithmétique ; "model_probability" est en fait la confiance pondérée du pick consensus, et rien n'empêche le LLM d'inventer une value sur un pick que le modèle n'a pas fait.
- **Risk Killer** : renvoie `risk_score` 0-100, prompt explicitement pessimiste.
- **Match Analyst** : verdict sur Under 2.5 uniquement.
- **Final Judge** : règles dures PHP `risk > 80 → NO BET`, `value > 70 et risk < 50 → BET`, sinon Claude tranche (LEAN attendu). Décision Under 2.5 100 % PHP (`decideUnderMarket`, l.125-221) : verdict analyste ajuste la marge (+5/−10) et le risque (−10/+15) ; puis `margin > 20 et risk < 80 et global_confidence ≥ 57 → BET` ; `margin > 20 et risk < 80 et conf < 57 → LEAN` ; `margin > 20 et risk ≥ 85 → LEAN` ; `margin > 15 et risk < 85 → LEAN` ; sinon NO BET. Ces seuils ont été fixés à la main après deux journées (21-24 avril puis 25-26 avril 2026, `Claude.md` "Calibration v2").
- **Combo Builder** : choisit un marché par match parmi les candidats, cible 2.00 ; le prompt interdit quasiment les Over ("Never select Over markets unless explicitly justified"). Recommandation forcée en PHP : conf < 60 AVOID, ≤ 75 LEAN, > 75 BET (`ComboBuilderService.php:253-262`).

### 4.8 Sélection de combinés (`ComboSelectorService`)

- Paramètres `.env` : `COMBO_MIN_CONFIDENCE=65`, `COMBO_MIN_ODDS=1.85`, `MAX=2.20`, `TARGET=2.00`, `MIN_MATCHES=4`, `MAX=5`, `COMBO_OVER_SAFE_MAX_ODDS=1.45` (lus via `env()` dans le constructeur, l.45-53).
- Candidats par match (l.187-383) : "général" (meilleure reco si conf ≥ 65 et cote > 1.0) ; Under 3.5 sinon Under 2.5 + BTTS No si `decision_under ∈ {BET, LEAN}` ; Over 2.5 (cote ≤ 1.45) sinon Over 1.5 si `decision_global ∈ {LEAN, BET}` ; DC 1X / X2 (cote ≥ 1.10) si `decision_global ∈ {LEAN, BET}`. Cote < 1.10 exclue.
- Score combo (l.513-562) : `0.4 × conf_moy + 0.3 × score_moy + 10 si CLV > 0 partout (jamais) + 0.2 × ai_score moyen (colonne inexistante, toujours null) + max(0, 30 − 100 × |cote − 2.00|)`. Le dernier terme domine : le combo retenu est surtout celui dont la cote est la plus proche de 2.00.
- Whitelist de 11 ligues (l.26-38) : coupes d'Europe et 7 ligues exclues.

---

## 5. Marchés couverts

| Marché | Layer 1 (Source D) | Cote lue | Décision IA | Candidat combo | Logique |
|---|---|---|---|---|---|
| 1X2 (`winner`) | oui, pick = max(P) | `odds_home/draw/away` | seulement via `decision_global` | seulement comme "pick général" | dérivée du Poisson commun |
| Over/Under 2.5 (`overUnder`) | oui, pick Over ou Under | `odds_over_2_5/under_2_5` | **Under 2.5 = seul marché avec logique dédiée** (Match Analyst, `decideUnderMarket`, `results:collect`) | Under 3.5 → Under 2.5 ; Over 2.5 → Over 1.5 | dérivée du Poisson ; ligne 3.5 et 1.5 non modélisées (cote uniquement) |
| BTTS (`btts`) | oui | `odds_btts_yes/no` | non | BTTS No uniquement, corrélé à Under | dérivée du Poisson |
| Double Chance (`doubleChance`) | oui | `odds_dc_1x/12/x2` | via `decision_global` | 1X / X2 (jamais 12) | dérivée du 1X2 Poisson |
| Score exact (`exactScore`) | oui (top score) | aucune | non | non | dérivée du Poisson ; pèse 1/5 de la confiance globale |
| Totaux par équipe | non | colonnes présentes (manuel) | non | non | seulement `ValueAnalyzer::getOddsForBet` pour des libellés jamais produits |
| HT/FT, handicaps, cartons | non | — | — | — | absents |

Conclusion : un seul calcul probabiliste (Poisson) alimente tout ; il n'y a pas de logique spécifique par marché côté modèle. La seule spécialisation est décisionnelle et concerne Under 2.5.

---

## 6. Sorties

**Par match (Layer 1, table `recommendations`)** : une ligne par marché avec `market`, `bet` (`1|X|2`, `Over|Under`, `Yes|No`, `1X|12|X2`, `h-a`), `confidence` (entier 0-100, confiance pondérée, pas une probabilité calibrée), `score` (0-100+, priorité), `level` (1-4), `odds` (cote au moment de l'analyse ou null), `consensus_type`, `consensus_agreement`, `predictions` (détail par source), `logic` (texte). Sur `matches` : `global_confidence`, `layer1_score`, `layer2_score`, `convergence`. Les champs value (`valueVerdict`, `edge`, `kellyStake`) sont calculés mais non persistés dans le flux API. **Aucune abstention à ce niveau** : les 5 marchés sont toujours produits.

**Par match (IA, table `ai_analysis`)** : `match_analyst_output`, `value_output`, `risk_output` (JSON bruts des LLM) et `final_decision` : `{decision, decision_global: BET|NO BET|LEAN, decision_under: BET|NO BET|LEAN, under_margin, under_reasoning, analyst_verdict, confidence, dominant_factor, edge_quality, reasoning, failure_scenario}`. Abstention = `NO BET`.

**Par jour (`daily_combos`)** : rank 0 (IA) et 1-3 (algo) avec `picks` (JSON : match, marché, pick, cote, confiance, stratégie, raison), `total_odds`, `combo_score`, `avg_confidence`, `logic`. `won`/`profit` jamais renseignés. Abstention : moins de 4 matchs éligibles → rien ; IA AVOID → pas de rank 0.

**Fichiers** : `storage/app/private/reports/ai_batch_{date}.json` (picks + décisions + tokens) et `ai_results_{date}.json` (résultat Under 2.5 par match + win rate BET/LEAN).

**UI** : `/analysis` (liste + bouton analyser), `/analysis/results` et `/history/{id}` (recommandations, insights Layer 2), `/combos`, `/backtest`, `/market` (CLV), `/dashboard` (Win Rate/ROI/Yield/Drawdown **issus du dernier backtest**, cf. §8 sur la validité de ces chiffres), `/history` avec export CSV.

**Seuils d'abstention effectifs** : `FinalJudge` (risk > 80 → NO BET ; Under : marge ≤ 15 → NO BET), `ComboSelector` (conf < 65 pour le pick général, cote < 1.10, hors fenêtre 1.85-2.20, < 4 matchs), `ComboBuilder` (conf < 60 → AVOID).

---

## 7. Historique et mesure

**Ce qui existe.**

1. **Rapports JSON IA** (`storage/app/private/reports/`) : 11 `ai_batch` (2026-04-25 → 2026-05-05) et 10 `ai_results` (2026-04-25 → 2026-05-04). Seul suivi de résultats réels du système. Il ne mesure qu'une chose : "le total de buts était-il ≤ 2 ?" pour chaque match analysé, croisé avec `decision_under`. Agrégat des 10 fichiers :

| Population | Under 2.5 gagné | Total réglé | Taux |
|---|---|---|---|
| Tous les matchs analysés | 98 | 232 | 42 % |
| `decision_under = BET` | 6 | 12 | 50 % |
| `decision_under = LEAN` | 15 | 42 | 36 % |
| `decision_under = NO BET` (Under aurait gagné) | 77 | 178 | 43 % |

Les décisions BET/LEAN ne font pas mieux que l'absence de décision. Dans les 11 `ai_batch` (235 analyses) : `decision_global` = NO BET 234 fois, LEAN 1 fois, BET 0 fois ; risk > 80 dans 93 cas. Aucun rapport ne contient de combo (`combos: []`).

2. **Backtest** (`backtest_runs`, `backtest_predictions`, page `/backtest`) : simule le Poisson sur des matchs terminés, calcule win rate, ROI, yield, drawdown, Brier, calibration par tranche, segmentation ligue/marché/confiance/cote. Nombre de runs non vérifiable (DB inaccessible). `Claude.md` cite un run : 17 matchs, 68 prédictions, DC 76.5 %, 1X2 52.9 %. Voir §8 : les métriques financières sont calculées à des cotes fictives.

3. **Historique React importé** : `storage/app/private/history-laravel-2025-12-06_v2.json` (33 matchs validés, sources A/B/C manuelles) ; c'est la base des fiabilités de `config/reliability.php`. Tables `match_validations` et colonnes `recommendations.validated` / `combos.validated` ne sont alimentées que par ces imports.

4. **CLV** (`odds_movements`, `odds_at_pred_*`, `odds_closing_*`) : mécanisme complet, dépend de lancer `market:track snapshot` puis `close` à la main. Aucune preuve d'utilisation régulière dans les logs.

**Ce qui n'existe pas.**
- Aucune trace du résultat réel des recommandations Layer 1 (1X2, BTTS, DC) pour les matchs du pipeline : `recommendations.validated` n'est jamais écrit par le flux API.
- Aucun suivi du résultat des combinés (`daily_combos.won` jamais écrit ; aucun code ne le calcule).
- `ai_analysis.ai_vs_real_result` prévu, jamais écrit.
- La page `/statistics` (taux de réussite par marché/consensus/source) n'est pas routée et planterait (`$match->validations` n'existe pas).
- Aucune métrique de calibration sur les prédictions réelles (le Brier n'existe que dans le backtest).
- Aucun test automatisé du métier (`tests/` = 25 tests Breeze auth/profil + 2 exemples).
- Volume DB annoncé (`Claude.md`) : ~960 matchs importés janvier-avril 2026 par `pipeline:backfill`. Non vérifié.

---

## 8. Failles et dettes

### Gravité 1 — invalide les conclusions ou la mesure

1. **Le backtest calcule ROI/yield/bankroll à des cotes fictives.** `BacktestEngine::makePrediction()` renvoie toujours `odds => null` (l.378-411) ; `simulatePredictions()` fait alors `odds = 100 / probabilité_modèle` (l.265, 469-472). Les cotes réelles présentes en base (`_odds`) ne servent qu'à estimer λ. Conséquences : le ROI mesure l'écart entre fréquence observée et probabilité annoncée, pas une rentabilité ; la stratégie "kelly" a un edge de 0 par construction (`prob × (1/prob) − 1`) et mise 0 ; le dashboard affiche ces chiffres comme "Win Rate / ROI / Yield backtest récent" (`dashboard.blade.php:11-15`).

2. **Le backtest ne teste pas le modèle de production.** `estimateLambdas()` (l.306-361) : grille pas 0.1 sans erreur sur le nul, sans phase O/U, sans signaux `xg_proxy`/`comparison`/blessures ni avantage domicile. `XGModelService` fait tout cela. Les 68 prédictions citées ne disent rien du modèle réellement utilisé.

3. **Fuite temporelle probable sur les données historiques.** `pipeline:backfill` et tout `pipeline:run-sync` sur une date passée appellent `getFullMatchData()` après le match : `getTeamStatistics` (forme et stats à la date de l'appel), `getStandings` (classement actuel), `getLineups` (compos réelles), `getPredictions` (prédictions API recalculées avec les stats à jour), `getInjuries`. Tout est écrit dans `advanced_data` sans horodatage de "connaissance". Le backtest utilise `comparison` issu de ces prédictions quand les cotes manquent (l.341-345). Les 960 matchs historiques sont donc contaminés pour toute évaluation Layer 2 / IA. `getFixtureOdds` sur une date passée : comportement de l'API inconnu (question ouverte).

4. **Les cotes utilisées sont la meilleure cote parmi 13 à 23 bookmakers**, pas une cote moyenne ni celle d'un bookmaker jouable. `parseFixtureOdds` (`ApiFootballService.php:334-393`, `applyMax`) et `normalizeOdds` (`OddsApiService.php:341-492`) prennent le max ; `FetchOddsJob` écrase ensuite les cotes API-Football avec le max The Odds API (`MatchEnricherService.php:141-174`, confirmé par les logs : "TheOddsApi/CLV : cotes enrichies … bookmakers 23"). Les probabilités implicites sont sous-estimées, les "edges" surestimés, et les combos affichent des cotes qu'aucun bookmaker unique ne propose. La doc interne (`Claude.md`) affirme le contraire.

5. **Aucune mesure fiable des performances réelles.** Voir §7 : le seul suivi (Under 2.5, 232 matchs) montre BET 50 % (n=12) et LEAN 36 % (n=42) contre 43 % en NO BET. Ni les recommandations 1X2/BTTS/DC ni les combos ne sont jamais confrontés au résultat. L'objectif "70-80 % sur combos" n'est mesuré nulle part.

6. **La confiance globale est structurellement écrasée.** `calculateGlobalConfidence()` moyenne les 5 marchés dont le score exact (~10 %) et un 1X2 souvent < 50 % ; puis Layer 2 (≈50) la tire vers 50 avec 40 % de poids. Résultat observé : 47-58 sur 235 matchs. Or `decideUnderMarket` exige ≥ 57 pour un BET, et `decision_global` BET (`value > 70 et risk < 50`) n'a jamais été atteint. Le système est calibré pour ne presque jamais parier, non par prudence mesurée mais par construction arithmétique.

### Gravité 2 — bugs fonctionnels

7. **H2H Layer 2 toujours neutre dans le flux API.** `MatchEnricherService::normalizeH2H()` produit une liste de matchs (l.257-272) ; `H2HAnalyzer::analyze()` attend `totalGames/homeWins/awayWins/atHomeAdvantage/lastMatches` (l.26-30). Résultat : `h2hScore = 50`, `confidence = 0`, avertissement "Aucun H2H disponible". Le seul analyseur Layer 2 "à valeur unique" ne fonctionne jamais.

8. **Layer 2 réduit à la tactique et au contexte**, et la tactique est souvent absente (lineups indisponibles avant l'heure du match). Les analyseurs forme / blessures / xG (poids déclarés 68 % cumulés) ne sont pas branchés. `RulesService` jamais appelé (`AnalyzerService.php:214` met `special_rule` à null). Le code ne fait pas ce que sa documentation et ses commentaires décrivent.

9. **Sources manuelles fantômes.** `collectSources()` du formulaire (`manual-input.blade.php`) envoie toujours A, B, C avec des valeurs par défaut (`'1'`, `'Under'`, `'No'`, `'1X'`, confiances 50/67/63/64/75…) même si l'utilisateur n'a rien renseigné. Toute analyse manuelle inclut donc trois sources fictives biaisées vers domicile / Under / BTTS No / 1X, comptées dans le consensus.

10. **Double encodage JSON dans le chemin manuel.** `save()` (`MatchAnalysisController.php:1236-1355`) fait `json_encode()` sur `context`, `predictions`, `warnings`, `value_analysis`, `tactical_data`… alors que les modèles ont un cast `array` (`FootballMatch.php:115`, `Recommendation.php:33-40`, `AdvancedData.php:31-41`, `Source.php:19-21`). Les valeurs sont stockées comme chaînes JSON encodées deux fois ; à la relecture, `Source::getDecodedPredictions()` renvoie `[]`, donc les sources sauvegardées sont inutilisables. Même problème dans `DataImportService` (l.269, 350).

11. **Champs silencieusement ignorés.** `Recommendation::$fillable` (l.13-31) ne contient pas `value_analysis/value_verdict/value_message/value_warning/value_bonus` (colonnes existantes) ; `Source::$fillable` ne contient pas `winner_prediction/…` passés par `save()` (l.1287-1313) ni `winner_pick/…` passés par `ImportReactHistory` (l.226-235) ; `ComboSelector` lit `advanced_data.ai_score` qui n'existe pas (l.395). Laravel n'est pas en mode strict : tout est perdu sans erreur.

12. **`--force` de `ai:batch` inopérant** pendant 6 h : les agents lisent leur cache (`ai_value_{id}` etc.) avant tout appel (`ValueHunterService.php:62-66`, idem Risk, Judge, Analyst). Recalibrer les seuils PHP du Final Judge et relancer donne le même `value_output`, mais `decideUnderMarket` étant recalculé, on peut croire à tort que l'IA a été relancée. `total_input_tokens` stocké par match est en réalité le cumul depuis le début du batch (`AiBatchAnalyze.php:105-106`).

13. **Parsing du conseil API-Football inversé** (`AnalyzerService.php:128-133`) : "…and -3.5 goals" contient "goals" → classé Over. Source E vote Over 55 % sur des matchs où l'API suggère Under.

14. **Prompt Match Analyst alimenté avec des données cassées** : `summarizeForm()` (`MatchAnalystService.php:233-252`) attend des objets et reçoit des lettres ; le LLM voit `????? (0 BF, 0 BC)` comme forme récente. Météo : `guessCountry` ne couvre que FR/GB/ES/IT/DE (l.344-361) ; `guessCity` prend le nom d'équipe comme ville en repli ("Nottingham Forest"). Arbitre : `analyzeReferee` exige `$fullMatchData` que personne ne passe (l.372-375) → toujours indisponible ; la table `referees` ne se remplit que de défauts. Les logs du 2026-05-05 confirment : dimensions enrichies = `["fatigue","coachPressure"]` seulement.

15. **Scores manquants pour AET/PEN** : `upsertFromApiFootball()` met `completed = true` pour FT/AET/PEN mais ne copie le score que pour FT (l.47-49). Les matchs de coupe prolongés restent "EN ATTENTE" dans `results:collect` et sont exclus du backtest.

16. **Route `/history/{id}` fragile** : `buildAnalysisData()` fait `json_decode($match->context, true)` (l.276) sur un attribut casté `array`. Pour les matchs importés de React (contexte stocké en tableau), `json_decode(array)` lève un `TypeError` en PHP 8 (vérifié). Non testé en conditions réelles faute d'accès DB ; probable.

17. **`apiAnalyze` cassé et non routé** : passe un tableau à `AnalyzerService::analyze(FootballMatch)` (l.1509) → TypeError ; `routes/api.php` n'est de toute façon pas chargé par `bootstrap/app.php`.

18. **Confiances réattribuées entre marchés dans les combos** : le candidat "Under 3.5" porte la confiance et le score du pick Under 2.5 (`ComboSelectorService.php:241-258`) ; DC 1X/X2 porte celle de la meilleure reco, quel que soit son marché (l.340-361). `avg_confidence` des combos n'a pas de sens.

### Gravité 3 — valeurs arbitraires et dette de conception

19. Toutes les constantes du §4 sont choisies à la main ; la seule "calibration" documentée (`Claude.md`) consiste à avoir relâché les seuils Under 2.5 après un week-end à 48 %, puis à les avoir resserrés après un autre. Deux journées ne calibrent rien.
20. Fiabilités A/B/C sur 33 matchs (≈30 à 44 picks par marché) : intervalle de confiance de ±15 points ; les distinctions 0.86 / 0.90 / 0.88 sont du bruit.
21. Avantage domicile appliqué après une fusion qui contient déjà le marché ; commentaire (35 %) et code (40 %) divergent (`XGModelService.php:26-31, 412-421`).
22. Poisson sans corrélation des scores faibles, tronqué à 6 buts, présenté comme "Dixon-Coles" dans les commentaires et la doc.
23. `xg_proxy` = pourcentage de victoire × 0.03 : sémantiquement faux, donné à 20 % de poids.
24. Le consensus D+E est binaire (TOTAL ou CONFLIT) : +25 points de score dès que le modèle Poisson et l'API-Football (elle-même Poisson sur cotes) sont d'accord, ce qui est presque toujours le cas sur DC. Le score "priorité" récompense la redondance.
25. Toute la couche IA est orientée Under 2.5 (Match Analyst, `decideUnderMarket`, `results:collect`, prompt Combo Builder anti-Over) alors que le modèle et les rapports ne montrent aucun avantage sur ce marché (42 % de Under observés sur 232 matchs, sous le taux de base européen d'environ 48-50 %).
26. `env()` appelé dans les services (`ClaudeClient`, `WeatherService`, `ComboSelectorService`, `FetchMatchDataJob`, `helpers.php`, routes) : cassé dès que `config:cache` est activé. `WeatherService` lit `config('app.openweathermap_base_url')` qui n'existe pas.
27. Cache/queue/session en base MariaDB ; le scheduler est désactivé ; tout repose sur des commandes manuelles.
28. Explosion combinatoire possible dans `generateMultiMarketCombinations()` (C(n,5) × produit des candidats) sans borne sur n ; élagué seulement par la cote max.
29. `MatchAnalysisController` : ~700 lignes de méthodes non routées (statistics, exports, imports, saveAll) référençant une relation inexistante et une classe non importée (`MatchValidation`, l.697).
30. `Claude.md` : ligues 113/119/103 "à réactiver en août 2026" ; nous sommes en septembre 2026, elles sont toujours inactives. `.env` (`ANTHROPIC_MODEL=claude-sonnet-4-20250514`) et doc (`claude-sonnet-4-6`) divergent.
31. Sécurité : clés API en clair dans `.env` du dossier (pas de git, donc pas de fuite par commit, mais la page `/settings` et les logs exposent l'état des clés) ; `laravel.log` de 1.3 Mo en `debug` avec les en-têtes de quota.
32. Zéro test sur le métier ; les commandes `test:*` sont des scripts d'exploration, pas des tests.

---

## 9. Questions ouvertes

1. **Volumes réels en base** : nombre de matchs, de `ai_analysis`, de `daily_combos`, de `backtest_runs` ; combien de matchs ont réellement `odds_under_3_5`, `context_data.weather`, `tactical_data` ? (DB inaccessible pendant l'audit.)
2. **API-Football `/odds` sur des fixtures passées** : renvoie-t-il des cotes (et lesquelles : pré-match ou clôture) ? Cela détermine si les 960 matchs historiques ont des cotes exploitables et si le backfill avec cotes est une fuite.
3. **The Odds API** : est-ce voulu que `FetchOddsJob` écrase les cotes API-Football avec la meilleure cote de 23 bookmakers ? Sur quel bookmaker les paris seraient-ils réellement placés ?
4. **Résultats des combinés** : les combos générés depuis le 27 avril ont-ils été suivis quelque part (tableur, notes) ? Rien en base ni dans les rapports.
5. **Origine des constantes** : `LEAGUE_AVG_XG_BY_ID`, fiabilités D/E, poids 0.20/0.30/0.40, +15 fatigue européenne, seuils 20 %/15 %/57 %… Existe-t-il un carnet de calibration, ou tout vient-il de discussions avec un assistant ?
6. **Le backtest de `Claude.md` (DC 76.5 %, 1X2 52.9 %)** : quelle configuration, quelles cotes, quelle période ? Le run est-il encore en base ?
7. **Sources A/B/C** : sont-elles encore saisies aujourd'hui ? Si non, `reliability.php` et le formulaire manuel sont morts et le consensus se réduit à D vs E.
8. **Lineups** : à quelle heure le pipeline tourne-t-il réellement par rapport au coup d'envoi (14 h UTC selon `.env`) ? Les lineups sont publiés ~1 h avant ; la dimension tactique est-elle jamais renseignée en pré-match ?
9. **Phase 5.3-5.6** (Market Reader, Narrative, orchestrateur, backtest IA vs sans IA) : abandonnées ou en attente ? Les colonnes `market_output`/`narrative_output` existent, vides.
10. **Objectif de mesure** : quelle métrique fait foi pour décider que le système "marche" (win rate combos, CLV, yield à cote réelle) et sur combien de paris ? Aujourd'hui aucune n'est instrumentée de bout en bout.
11. **Timezone** : les dates de pipeline et d'affichage sont en UTC / Indian/Antananarivo (UTC+3) ; un match à 22 h UTC appartient au lendemain local. Le filtre horaire 12-21 UTC est-il intentionnel pour exclure ces matchs ?
