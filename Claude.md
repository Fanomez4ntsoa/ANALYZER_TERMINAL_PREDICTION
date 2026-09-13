# CLAUDE.md — Système de Prédiction Football Premium

> Ce fichier est la feuille de route principale du projet.
> Claude Code doit le lire au début de chaque session et s'y référer pour toute décision d'implémentation.

---

## Contexte du projet

Système de prédiction football personnel, long terme, avec pour objectif d'atteindre **70-80% de réussite sur des combos 3-4 matchs à cote totale 1.90~2.10**.

Le projet Laravel existant a déjà une architecture analytique sérieuse (Layer 1 + Layer 2, Kelly Criterion, Dixon-Coles, calibration empirique). L'objectif n'est pas de tout reconstruire — c'est d'**automatiser, enrichir et valider** ce qui existe déjà.

**Stack :** Laravel, PHP, Blade + Tailwind, MySQL

**APIs à intégrer :**
- `API_FOOTBALL_KEY` → api-football.com (stats, H2H, forme, blessures, lineups)
- `ODDS_API_KEY` → the-odds-api.com (cotes multi-bookmakers en temps réel)
- `ANTHROPIC_API_KEY` → api.anthropic.com (analyse IA narrative, Claude)
- `OPENWEATHERMAP_KEY` → openweathermap.org (météo pour contexte situationnel)

---

## Règles de travail pour Claude Code

1. **Toujours lire ce fichier en début de session** avant toute action
2. **Ne jamais casser la logique métier existante** — Layer 1, Layer 2, Kelly Criterion, RulesService sont intouchables sauf instruction explicite
3. **Corriger avant d'ajouter** — aucune nouvelle fonctionnalité sur une base buguée
4. **Montrer chaque modification avec explication** avant d'appliquer
5. **Committer par phase** avec des messages clairs
6. **Tester chaque intégration API** avec un endpoint simple avant de construire dessus
7. **Mettre à jour ce fichier** à la fin de chaque phase (section Progression)

---

## Architecture cible

```
Scheduler (cron quotidien, créneau configurable ex: 18h-23h)
    │
    ▼
FetchMatchDataJob → API-Football
    (fixtures à venir, H2H, forme, blessures, lineups, xG)
    │
    ▼
FetchOddsJob → The Odds API
    (cotes multi-bookmakers, détection mouvement)
    │
    ▼
FetchWeatherJob → OpenWeatherMap
    (météo lieu du match)
    │
    ▼
MatchEnricherService → Normalise + Stocke en DB
    │
    ▼
AnalyzerService Layer 1 (existant, automatisé)
    ├── Consensus (plus de saisie manuelle)
    ├── CalculatorService
    ├── RulesService
    └── ValueAnalyzer + CLV tracking
    │
    ▼
Layer2Service (existant, alimenté par API)
    ├── FormAnalyzer
    ├── InjuryAnalyzer
    ├── H2HAnalyzer
    ├── TacticalAnalyzer
    ├── XGAnalyzer
    └── ContextAnalyzer (enrichi météo, arbitre, enjeu, fatigue)
    │
    ▼
ClaudeAnalysisService → Claude API
    (analyse narrative, signaux humains, risques non détectés)
    │
    ▼
ComboSelectorService
    (3-4 matchs, cote cible 1.90~2.10, anti-corrélation)
    │
    ▼
Notification (email ou Telegram)
    +
Dashboard premium mis à jour
```

---

## Roadmap par phases

### PHASE 0 — Fondation (priorité absolue)
**Durée estimée : 2-3h**
**Statut : 🔴 À faire**

Objectif : base saine + données automatiques

#### 0.1 Corrections de bugs critiques ✅ (2026-04-05)
- [x] Double appel `applySpecialRules` dans `AnalyzerService.php` — supprimé le doublon + doublon `getBetFromConsensus`
- [x] Incohérence noms de marchés dans `ValueAnalyzer` — `getOddsForBet()` accepte maintenant les deux formats (`'winner'`/`'Winner'`, etc.)
- [x] Casts commentés dans `Recommendation.php` — activé casts `predictions`, `warnings`, `value_analysis` en array
- [x] Double décodage JSON dans `Source.php` — ajouté cast `'predictions' => 'array'`, simplifié `getDecodedPredictions()`
- [x] Nettoyé 5 occurrences de `json_decode()` manuel dans `MatchAnalysisController.php` (conséquence des casts activés)

#### 0.2 Intégration API-Football ✅ (2026-04-05)
- [x] Créer `app/Services/Api/ApiFootballService.php` — 15 méthodes publiques
- [x] Endpoints : `/fixtures`, `/fixtures/headtohead`, `/teams/statistics`, `/injuries`, `/predictions`, `/fixtures/lineups`, `/standings`, `/fixtures/statistics`
- [x] Méthode `getFullMatchData()` — récupère tout en un appel pour le pipeline
- [x] Cache Laravel par type de donnée (TTL configurable dans `config/api-football.php`)
- [x] Commande de test `php artisan api-football:test` (--fixtures, --fixture-id, --date, --search-team, --status)
- [x] Testé OK : fixtures, blessures, prédictions, recherche d'équipe fonctionnels
- [x] `API_FOOTBALL_KEY` dans `.env` — plan gratuit 100 req/jour

#### 0.3 Intégration The Odds API ✅ (2026-04-05)
- [x] Créer `app/Services/Api/OddsApiService.php` — 12 méthodes publiques
- [x] Endpoints : `/sports` (gratuit), `/events` (gratuit), `/scores` (gratuit), `/sports/{key}/odds` (payant)
- [x] Cache agressif 2h minimum sur les cotes, 6h sur les events
- [x] Compteur mensuel de quota avec alerte à 400/500 req + blocage automatique à 500
- [x] Matching flou entre noms d'équipes API-Football ↔ The Odds API (similar_text ≥ 70%)
- [x] `normalizeOdds()` → meilleures cotes + moyennes multi-bookmakers
- [x] `findOddsForMatch()` → pont direct entre API-Football et The Odds API
- [x] Mapping league ID → sport key dans `config/odds-api.php`
- [x] Commande `php artisan odds-api:test` (--sports, --events, --odds, --match, --quota)
- [x] Testé OK : 24 bookmakers, Ligue 1 cotes récupérées, matching Monaco/Marseille validé (coût: 2 crédits)

#### 0.4 Pipeline de base ✅ (2026-04-05)
- [x] Créer `app/Jobs/FetchMatchDataJob.php` — récupère fixtures + enrichit données avancées, puis chaîne FetchOddsJob
- [x] Créer `app/Jobs/FetchOddsJob.php` — 1 appel par ligue (pas par match), quota-aware
- [x] Créer `app/Services/DataPipeline/MatchEnricherService.php` — normalise API-Football → DB + cotes → DB
- [x] Migration `add_api_columns_to_matches_table` — api_football_id, odds_api_event_id, team IDs, league_id, data_source, enriched_at
- [x] Scheduler dans `routes/console.php` — 2 passages/jour configurables via `PIPELINE_SCHEDULE_TIME` et `PIPELINE_SCHEDULE_UPDATE`
- [x] Commandes `pipeline:run {date}` (async) et `pipeline:run-sync {date}` (synchrone)
- [x] Testé OK : 14 matchs créés + 14/14 enrichis avec cotes multi-bookmakers, coût 8 crédits Odds API

**Validation Phase 0 :** ✅ Le système récupère et stocke des matchs + cotes automatiquement sans aucune saisie manuelle.

---

### PHASE 1 — Modèle probabiliste maison
**Durée estimée : 3-4h**
**Statut : ✅ Terminée**
**Dépendance : Phase 0 terminée**

Objectif : ne plus dépendre des probabilités de good-sport/mybets/probabilities

- [x] Créer `app/Services/Probability/PoissonModelService.php`
  - Distribution de Poisson complète (matrice de scores 0-6 × 0-6)
  - Prédictions : 1X2, Over/Under, BTTS, Double Chance, scores exacts
- [x] Créer `app/Services/Probability/XGModelService.php`
  - 4 signaux fusionnés : xG proxy (20%), comparaison API-Football 7D (30%), reverse-Poisson depuis cotes (40%), blessures (10%)
  - Ajustement domicile/extérieur (+8% home, -5% away)
  - Déduplication blessures (bug connu des données API)
- [x] Intégrer comme "Source D" dans le pipeline Layer 1 existant
  - `AnalyzerService` injecte Source D automatiquement si données disponibles
  - `CalculatorService` et configs `reliability.php` / `analyzer.php` mis à jour
  - Sources A/B/C inchangées — Source D s'ajoute au consensus
- [x] Calibration initiale : écart moyen 1X2 = 3.2% vs marché (< 5% = bien calibré)
- [x] Commande `php artisan source-d:test` (--all, --match-id, --compare)
- [x] Testé sur 14 matchs en DB — prédictions cohérentes

**Validation Phase 1 :** ✅ Le système génère ses propres probabilités indépendamment des sites externes.

---

### PHASE 2 — Intelligence de marché
**Durée estimée : 2-3h**
**Statut : ✅ Terminée**
**Dépendance : Phase 0 terminée (peut être parallèle à Phase 1)**

Objectif : savoir si tu bats le marché

- [x] **CLV Tracker** — `app/Services/Market/CLVTrackerService.php`
  - `snapshotOdds()` — capture cotes à un instant T, 0 crédit si cache dispo
  - Premier snapshot = `odds_at_pred_*` automatiquement
  - `markClosingOdds()` — marque le dernier snapshot comme cote de clôture
  - `calculateCLV()` — CLV = (cote_prise / cote_clôture - 1) × 100
  - `getSummary()` — CLV moyen, % positif, détail par match
- [x] **Table `odds_movements`** — snapshots horodatés avec % de variation
  - Migration + modèle `OddsMovement`
  - Colonnes CLV ajoutées à `matches` : `odds_at_pred_*`, `odds_closing_*`, `predicted_at`
- [x] **Sharp money detection** intégrée dans chaque snapshot
  - Score 0-100 : mouvement >5% (30pts), asymétrique (25pts), >8% (20pts), O/U concordant (15pts), multi-bookmakers (10pts)
  - Alerte automatique si score ≥ 60
- [x] **Commande `php artisan market:track`** — 5 actions :
  - `snapshot` — prendre un snapshot (0-5 crédits max)
  - `close` — marquer les cotes de clôture
  - `clv` — afficher CLV summary ou par match (--match-id)
  - `movements` — historique des mouvements par match
  - `summary` — résumé complet du jour
- [x] Testé : 14 snapshots créés (0 crédit), CLV calculé et affiché, sharp money detection fonctionnelle

**Validation Phase 2 :** ✅ Tu peux mesurer si tes prédictions battent le marché via CLV, et détecter le sharp money.

---

### PHASE 3 — Contexte situationnel enrichi
**Durée estimée : 2-3h**
**Statut : ✅ Terminée**
**Dépendance : Phase 0 terminée**

Objectif : enrichir le `ContextAnalyzer` avec des dimensions que les algos classiques ignorent

- [x] **Fatigue calendaire** — `ContextEnricherService.analyzeFatigue()`
  - Matchs joués sur 7/14/21 jours depuis la DB
  - Surcharge européenne (+15 pts si compétition C1/C3/Conference)
  - Score fatigue 0-100 par équipe
- [x] **Enjeu précis du match** — `ContextEnricherService.analyzeStakes()`
  - Classification auto : title, champions_league, european, mid_table, relegation_danger, relegation
  - Coefficient de motivation (55-95) avec différentiel
  - Résolution d'importance : enjeu + pression coach → importance finale
- [x] **Météo** via OpenWeatherMap — `WeatherService.php`
  - Vent > 40 km/h → Over -10%, BTTS -5%
  - Pluie → Over -5%
  - Neige → Over -10%, BTTS -8%
  - Chaleur > 35°C → Over +5%
  - Testé OK sur 14 matchs (pluie détectée à Metz)
- [x] **Historique arbitre** — table `referees` + modèle
  - Stats : jaunes/match, rouges/match, penalties/match, fouls/match
  - Style déduit : strict/moderate/lenient
  - Impact Over modifier si arbitre donne beaucoup de penalties
- [x] **Pression entraîneur** — `ContextEnricherService.analyzeCoachPressure()`
  - Score pression 0-100 basé sur défaites consécutives + bilan 5 derniers matchs
  - Style impact : neutral / cautious / desperate
- [x] **ContextAnalyzer** entièrement réécrit pour consommer les 5+1 dimensions
  - Retourne `overModifier` et `bttsModifier` pour Layer2Service
- [x] **Commande** `php artisan context:enrich` (--date, --match-id, --show)

**Validation Phase 3 :** ✅ Le ContextAnalyzer produit un score enrichi avec 6 dimensions (enjeu, repos, météo, fatigue, arbitre, pression).

---

### PHASE 4 — Backtesting segmenté sérieux
**Durée estimée : 3-4h**
**Statut : ✅ Terminée**
**Dépendance : Phase 0 + idéalement Phase 1**

Objectif : radiographie complète des performances réelles du modèle

- [x] Créer `app/Services/Backtesting/BacktestEngine.php`
  - Fetch matchs terminés via API-Football (1 appel/jour, max 40/run)
  - Simulation Poisson sur chaque match sans connaître le résultat
  - 3 stratégies de mise : flat, Kelly 1/4, proportionnelle
- [x] Créer `app/Models/BacktestRun.php` et `BacktestPrediction.php` + migration
- [x] Créer `app/Http/Controllers/BacktestController.php` + routes (GET/POST/show)
- [x] **7 métriques calculées** : Win Rate, ROI, Yield, Max Drawdown, Brier Score, Bankroll finale, Total matchs
- [x] **Segmentation 4 axes** : par ligue, par marché, par confiance (50-59/60-69/70-79/80+), par cote (1.01-1.40/1.41-1.80/1.81-2.50/2.51+)
- [x] **Calibration** : confiance prédite vs fréquence réelle par buckets de 10%
- [x] **Courbe bankroll** : évolution du capital sur toute la période
- [x] Dashboard backtest complet : formulaire config fonctionnel, métriques, graphique bankroll (Chart.js), segmentation en tables, courbe calibration (scatter), historique des runs
- [x] Fix `ApiFootballService.getFixturesByDate()` : paramètre `season` optionnel ajouté
- [x] Testé : 4 ligues, 4 jours, 17 matchs, 68 prédictions — Double Chance 76.5%, Winner 52.9%

**Validation Phase 4 :** ✅ Tu sais exactement sur quels segments ton modèle est profitable et sur lesquels il ne l'est pas.

---

### PHASE 5 — Système Multi-Agents IA
**Durée estimée : 4-6h**
**Statut : 🟡 En cours — 5.1 + 5.2 terminés, calibration Under 2.5 active**
**Dépendance : Phase 4 terminée**

Objectif : 6 agents IA spécialisés qui analysent chaque match sous un angle différent

**Architecture :**
```
app/Services/AI/
  ├── Agents/
  │   ├── ValueHunterService.php      ✅ Détection de value bets (math pure)
  │   ├── RiskKillerService.php       ✅ Élimination de risques (contrarian)
  │   ├── FinalJudgeService.php       ✅ Décision finale (synthèse)
  │   ├── MarketReaderService.php     ❌ Intelligence de marché (odds movements)
  │   ├── NarrativeService.php        ❌ Contexte humain (motivation, psychologie)
  │   └── HistoricalPatternService.php ❌ Patterns historiques
  └── AIOrchestratorService.php       ❌ Orchestre le pipeline d'agents
```

**Table `ai_analysis` :** match_id, value_output (json), risk_output (json), market_output (json), narrative_output (json), final_decision (json), ai_score, ai_vs_real_result

**Stratégie coût API :**
- Filtrer AVANT d'envoyer à l'IA : confiance algo > 60% ET value_margin > 3%
- Pipeline séquentiel : Value → Risk → si intéressant → Market → Narrative → Judge
- Cache 6h par match
- Modèle : claude-sonnet-4-6, max 1000 tokens par agent

**Sous-phases :**
- [x] 5.1 — ✅ ValueHunterService + RiskKillerService (testés, fonctionnels)
- [x] 5.2 — ✅ FinalJudgeService avec décision séparée Under 2.5
- [ ] 5.3 — ❌ MarketReaderService + NarrativeService
- [ ] 5.4 — ❌ HistoricalPatternService
- [ ] 5.5 — ❌ AIOrchestratorService (orchestre tout le pipeline)
- [ ] 5.6 — ❌ Backtest IA vs sans IA

**Calibration Under 2.5 (appliquée 2026-04-25) :**
- `decision_under` séparé de `decision_global` dans FinalJudgeService
- Seuils Under 2.5 plus permissifs (Risk Killer bloquait trop systématiquement) :
  - `margin > 20% ET risk < 80` → **BET Under**
  - `margin > 15% ET risk < 85` → **LEAN Under**
  - `risk >= 85` → NO BET
- ComboSelector : peut construire des combos Under 2.5 si `decision_under` ∈ {BET, LEAN}

**Calibration v2 FinalJudgeService (appliquée 2026-04-27) — sous-phase 5.2b ✅ :**

Affinage post-weekend 25-26 avril (Under 2.5 win rate descendu à 48%) — règles globales :
- `margin > 20% ET risk < 80 ET confidence >= 57%` → **BET**
- `margin > 20% ET risk < 80 ET confidence < 57%` → **LEAN** (downgrade)
- `margin > 20% ET risk >= 85` → **LEAN** (anciennement NO BET — garde de la value sans risque)
- `margin > 15% ET risk < 85` → **LEAN**
- `risk >= 85 ET margin <= 20%` → **NO BET**
- **Over exige maintenant `decision_global = LEAN/BET`** (aligné sur Double Chance)

**Validation Phase 5 :** Chaque match analysé a une décision BET/NO BET/LEAN avec justification multi-agents.

---

### PHASE 6 — Sélection combo automatisée
**Durée estimée : 1-2h (initial) + 4-5h (refonte 27/04/2026)**
**Statut : ✅ Terminée — refonte majeure 27/04/2026**
**Dépendance : Phase 5 terminée**

Objectif : sortir automatiquement les meilleurs combos du jour

#### 6.1 Version initiale (2026-04-12)

- [x] `ComboSelectorService.php` — logique complète :
  - Filtre confiance > COMBO_MIN_CONFIDENCE, cote > 1.0
  - Combinaisons 3-4 matchs, cote totale dans COMBO_MIN/MAX_ODDS
  - Anti-corrélation : pas 2 matchs même ligue
  - Scoring : confiance × score × bonus CLV + bonus IA + bonus cote cible
  - Top 3 sauvegardés en DB
- [x] `GenerateDailyCombosJob.php` — job async
- [x] `DailyCombo` modèle + migration `daily_combos`
- [x] Commande `php artisan combos:generate {date}`
- [x] Page `/combos` avec navigation date + affichage top 3
- [x] "Combos" ajouté dans la sidebar
- [x] Fix: try/catch autour du context enrichment et Layer2 dans `analyzeExistingMatch()`
- [x] Testé : 18 matchs → 7 eligible → 64 combos valides → top 3 sauvegardés

#### 6.2 Refonte majeure (2026-04-27)

**✅ Migration cotes vers API-Football `/odds`**
- Toutes les lignes Over/Under disponibles : **1.5, 2.5, 3.5, 4.5**
- BTTS Yes/No, Double Chance 1X / 12 / X2
- 13 bookmakers (Bet365 prioritaire — `preferred_bookmaker=8`, fallback all-bookmakers)
- **1 requête par match → 0.24% du quota quotidien** (7500 req/jour plan Pro)
- The Odds API conservé uniquement pour le **CLV tracker** (snapshots dans le temps)
- Implémenté via `ApiFootballService::getFixtureOdds()` + `MatchEnricherService::enrichWithApiFootballOdds()`
- Câblé dans `FetchMatchDataJob` (appelé par `pipeline:run-sync`)

**✅ Logique multi-marchés intelligente** (`ComboSelectorService::buildCandidatesForMatch`)
- **Under** : priorité **Under 3.5** → fallback **Under 2.5** si U3.5 indisponible
- **Over** : si cote Over 2.5 ≤ 1.45 → Over 2.5, sinon → **Over 1.5** (fallback safe)
- **BTTS No** : valide uniquement si corrélé avec direction Under (decision_under LEAN/BET)
- **Double Chance** : uniquement si `decision_global = LEAN ou BET`
- **Cote minimum** par pick : **1.10** (exclu si plus bas)

**✅ 6ème agent IA — `ComboBuilderService`** dans `app/Services/AI/Agents/`
- Reçoit les candidats du `ComboSelectorService`
- Optimise le choix de marché par match via Claude
- Vise cote totale **2.00** (fenêtre **1.85-2.20**)
- Output JSON strict : `picks`, `reasoning`, `safety_score`, `combo_confidence`, `failure_scenario`
- Recommendation : **BET** (>75) / **LEAN** (60-75) / **AVOID** (<60)
- Prompt système dynamique injecte `min/max picks` (= COMBO_MIN/MAX_MATCHES)
- Garde stricte côté code : combo rejeté si `count(picks) ∉ [min, max]`
- Sauvegardé en `daily_combos` à **rank=0** (distinct des combos algo rank 1-3)
- Modèle : `claude-sonnet-4-6`, max **1500 tokens**, cache **6h** (clé incluant min/max)

**✅ Règles strictes ComboSelectorService**
- Si `eligible < COMBO_MIN_MATCHES` → **aucun combo** (ni algo ni IA)
- Nettoyage automatique des combos périmés du jour avant chaque génération
- Whitelist `COMBO_ALLOWED_LEAGUES` exclut ligues inactives (113/119/103) et faibles échantillons (271/106/197)
- Picks avec cote < 1.10 exclus du candidate-pool
- **DC et Over exigent `decision_global = LEAN/BET`**
- **Under 3.5/2.5 valides même sur `decision_global = NO BET`** si `decision_under = LEAN/BET`

**Validation Phase 6 :** ✅ Pipeline génère un combo IA optimisé + top 3 algo, avec justification, anti-corrélation, et seuils auto-régulés.

---

### PHASE 7 — Dashboard premium
**Durée estimée : 3-4h**
**Statut : ✅ Terminée**
**Dépendance : peut commencer à tout moment, finaliser en dernier**

Objectif : interface qui reflète la qualité du système

- [x] Page `/dashboard` — Win Rate, ROI, Yield, Max Drawdown depuis backtest + courbe bankroll + perf par marché + statut pipeline + combos récents + activité récente
- [x] Page `/combos` (Phase 6) — combos recommandés avec justification
- [x] Page `/backtest` (Phase 4) — résultats backtesting avec graphiques
- [x] Page `/market` (Phase 2) — CLV tracker + mouvements de cotes
- [x] Page `/history` — filtres avancés (ligue, statut, convergence, confiance min, dates, recherche équipe) + Export CSV
- [x] Graphique courbe de bankroll simulée sur dashboard
- [x] Export CSV de l'historique avec filtres actifs

**Validation Phase 7 :** ✅ L'interface reflète fidèlement la performance réelle du modèle.

---

## Progression

| Phase | Statut | Date début | Date fin | Notes |
|-------|--------|------------|----------|-------|
| Phase 0 | ✅ Terminée | 2026-04-05 | 2026-04-05 | 4 bugs fixés, API-Football + Odds API + Pipeline fonctionnels |
| Phase 1 | ✅ Terminée | 2026-04-05 | 2026-04-05 | Poisson + XGModel, Source D intégrée, calibration 3.2% écart vs marché |
| Phase 2 | ✅ Terminée | 2026-04-05 | 2026-04-05 | CLV tracker, odds_movements, sharp money detection, commande market:track |
| Phase 3 | ✅ Terminée | 2026-04-05 | 2026-04-05 | 6 dimensions contextuelles, WeatherService, table referees, ContextEnricherService |
| Phase 4 | ✅ Terminée | 2026-04-05 | 2026-04-05 | BacktestEngine, 7 métriques, segmentation 4 axes, calibration, courbe bankroll |
| Phase 5 | 🟡 En cours | 2026-04-08 | — | 5.1+5.2 terminés + calibration v2 (2026-04-27), 5.3-5.5 (MarketReader/Narrative/HistoricalPattern) à venir, 5.6 backtest IA après validation semaine 28/04-02/05 |
| Phase 6 | ✅ Terminée (refonte 2026-04-27) | 2026-04-12 | 2026-04-27 | Initial : ComboSelectorService + DailyCombo. Refonte 27/04 : migration cotes API-Football /odds, logique multi-marchés (U3.5 prio, O1.5 fallback, BTTS-No, DC), 6ème agent ComboBuilder (rank=0), règles strictes |
| Phase 7 | ✅ Terminée | 2026-04-12 | 2026-04-12 | Dashboard premium avec KPI backtest, courbe bankroll, filtres avancés /history, export CSV |

---

## Schéma DB — colonnes de cotes (table `matches`)

Cotes peuplées par `MatchEnricherService::enrichWithApiFootballOdds()` (source API-Football)
ou via The Odds API (legacy / CLV) :

```
1X2          : odds_home, odds_draw, odds_away

Over/Under   : odds_over_1_5, odds_under_1_5
              odds_over_2_5, odds_under_2_5
              odds_over_3_5, odds_under_3_5
              odds_over_4_5, odds_under_4_5

Legacy O/U   : odds_over_2_0, odds_under_2_0     (migration gardée, non utilisée)
              odds_over_2_25, odds_under_2_25    (idem)

BTTS         : odds_btts_yes, odds_btts_no

Double Chance: odds_dc_1x, odds_dc_12, odds_dc_x2

Team totals  : odds_home_over_0_5/1_5, odds_home_under_0_5/1_5
              odds_away_over_0_5/1_5, odds_away_under_0_5/1_5

CLV tracking : odds_at_pred_*, odds_closing_*, predicted_at, odds_api_event_id
```

---

## Variables d'environnement

```env
# API-Football (RapidAPI)
API_FOOTBALL_KEY=
API_FOOTBALL_BASE_URL=https://v3.football.api-sports.io

# The Odds API
ODDS_API_KEY=
ODDS_API_BASE_URL=https://api.the-odds-api.com/v4

# Claude API (Anthropic)
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-sonnet-4-6

# OpenWeatherMap
OPENWEATHERMAP_KEY=
OPENWEATHERMAP_BASE_URL=https://api.openweathermap.org/data/2.5

# Timezone affichage (DB reste en UTC)
DISPLAY_TIMEZONE=Indian/Antananarivo

# Filtre horaire pipeline (heures UTC) — créneau soirée matchs européens
PIPELINE_MATCH_START_HOUR=15
PIPELINE_MATCH_END_HOUR=23

# Combo selector config (mis à jour 27/04/2026 — multi-marchés intelligent)
COMBO_MIN_CONFIDENCE=65
COMBO_MIN_ODDS=1.85
COMBO_MAX_ODDS=2.20
COMBO_TARGET_ODDS=2.00
COMBO_MIN_MATCHES=4
COMBO_MAX_MATCHES=5
COMBO_OVER_SAFE_MAX_ODDS=1.45

# The Odds API — gardé uniquement pour CLV tracker (snapshots historiques)
# Les cotes par match viennent désormais d'API-Football /odds (cf. Phase 6)
ODDS_API_FETCH_EXTRA=false
```

---

## Commandes disponibles

```bash
# Pipeline import / analyse
php artisan pipeline:run-sync {date}                    # Import matchs + cotes pour une date
php artisan pipeline:backfill --from= --to= --no-odds --delay=2   # Import historique en masse

# Analyse IA
php artisan ai:test --match-id=                         # Test Value+Risk+Judge sur un match
php artisan ai:batch --date= [--force]                  # Batch IA sur tous les matchs du jour
                                                        # → storage/app/private/reports/ai_batch_{date}.json
php artisan results:collect --date=                     # Collecte résultats réels + win rate BET vs LEAN
                                                        # → storage/app/reports/ai_results_{date}.json

# Marché / CLV
php artisan market:track snapshot                       # Snapshot des cotes (cache 2h)
php artisan market:track close                          # Marquer cotes de clôture
php artisan market:track clv                            # Afficher CLV summary
php artisan market:track movements                      # Mouvements de cotes
php artisan market:track summary                        # Vue d'ensemble du jour

# Contexte enrichi
php artisan context:enrich --date=                      # Enrichir tous les matchs d'une date
php artisan context:enrich --match-id= [--show]         # Match spécifique

# Backtest + combos
php artisan backtest:run                                # (via UI /backtest)
php artisan combos:generate {date}                      # Génère top 3 combos du jour

# Maintenance
php artisan app:reset [--force]                        # Vider toutes les tables sauf users
```

---

## Ligues configurées

21 ligues actives (`config/api-football.php` → `leagues[]`) :

**Top 5 européens** : 61 Ligue 1, 39 Premier League, 140 La Liga, 135 Serie A, 78 Bundesliga
**Coupes européennes** : 2 Champions League, 3 Europa League, 848 Conference League
**Autres ligues majeures** : 88 Eredivisie, 94 Primeira Liga, 144 Jupiler Pro League, 203 Süper Lig
**Secondes divisions** : 40 Championship (Eng D2), 62 Ligue 2 (Fra), 136 Serie B (Ita)
**Europe de l'Est et nordiques** : 271 Super Liga (Serbie), 106 Ekstraklasa (Pologne), 197 Super League (Grèce), 113 Allsvenskan (Suède), 119 Superligaen (Danemark), 103 Eliteserien (Norvège)

Mappings The Odds API (`config/odds-api.php`) : 19/21 mappés.
- Non mappés : `848` Conference League, `271` Super Liga Serbie (absents de The Odds API).
- Ces 2 ligues sont importées (matchs, blessures, prédictions) mais sans cotes automatiques.

### Ligues temporairement inactives (27/04/2026)

Flag `inactive_leagues` dans `config/api-football.php` — exclues du pipeline et des combos
tant qu'elles n'ont pas joué assez de journées (échantillon trop faible) :
- **113** Allsvenskan (Suède)
- **119** Superligaen (Danemark)
- **103** Eliteserien (Norvège)

→ **À réactiver en août 2026** quand 10+ journées seront jouées.

Whitelist combos `COMBO_ALLOWED_LEAGUES` dans `ComboSelectorService.php` exclut aussi
`271` Serbie / `106` Pologne / `197` Grèce (échantillons trop faibles ou debut de saison).

---

## Workflow quotidien

### Chaque soir (18h Madagascar = 15h UTC)

```bash
# 1. Récupérer matchs + cotes API-Football pour la date du jour
php artisan pipeline:run-sync $(date +%Y-%m-%d)

# 2. (UI) Aller sur /analysis pour analyser tous les matchs
#    OU lancer l'analyse en batch via les commandes existantes

# 3. Lancer le batch IA (Value + Risk + Judge)
php artisan ai:batch --date=$(date +%Y-%m-%d)

# 4. Générer combos (algo rank 1-3 + IA rank 0 via ComboBuilder)
php artisan combos:generate $(date +%Y-%m-%d)

# 5. Consulter /combos pour voir le combo recommandé du jour
```

### Lendemain matin

```bash
# 1. Pipeline du jour — met à jour automatiquement les scores J-1 (fix J-1 actif)
php artisan pipeline:run-sync $(date +%Y-%m-%d)

# 2. Collecter résultats réels + win rate des combos d'hier
php artisan results:collect --date=$(date -d 'yesterday' +%Y-%m-%d)
# → storage/app/reports/ai_results_{date}.json
```

---

## État actuel du système (2026-04-27)

- **960+ matchs** en DB (backfill janvier-avril 2026)
- **6 agents IA opérationnels** : Value Hunter, Risk Killer, Final Judge + ComboBuilder (3 autres prévus en Phase 5.3-5.5)
- **Under 2.5 win rate observé** :
  - **72%** semaine 21-24 avril (ancienne calibration)
  - **48%** weekend 25-26 avril (déclenche calibration v2)
- **Cotes multi-lignes disponibles** : Under 1.5/2.5/3.5/4.5, Over 1.5/2.5 + BTTS + DC (via API-Football, 13 bookmakers)
- **Phase de test active** — pas encore de paris réels
- **ComboSelector** génère un combo IA (rank=0) + top 3 algo (rank 1-3) **uniquement quand ≥ 4 matchs éligibles**
- **6ème agent ComboBuilder** opérationnel — modèle claude-sonnet-4-6, vise cote 2.00 (1.85-2.20)
- **Prochaine étape** : validation combos sur la semaine 28 avril - 2 mai (10+ combos générés et trackés)

---

## Objectif final

> Un système qui tourne seul chaque soir, récupère les données de matchs dans un créneau configuré, analyse chaque match avec les algorithmes existants + Claude, sélectionne automatiquement les meilleurs combos 3-4 matchs à cote 1.90~2.10, et notifie avec une justification complète et honnête.
>
> Pas pour vendre. Pour prouver que la combinaison d'une architecture analytique solide + IA peut atteindre un niveau de prédiction sérieux et mesurable.