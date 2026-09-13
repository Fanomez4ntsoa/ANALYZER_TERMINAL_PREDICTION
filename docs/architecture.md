# Architecture

État après l'étape 1 (13/09/2026). Décrit ce qui existe réellement, pas ce qui
est prévu.

---

## Le chemin d'une prédiction

```
pipeline:run-sync {date}
  └─ FetchMatchDataJob
       ├─ ApiFootballService::getFixturesByDate      fixtures du jour
       ├─ MatchEnricherService::upsertFromApiFootball → table matches
       ├─ ApiFootballService::getFullMatchData        H2H, stats, blessures,
       │                                              prédictions, lineups, classement
       ├─ MatchEnricherService::enrichWithAdvancedData → table advanced_data
       ├─ ApiFootballService::getFixtureOdds          cotes Bet365
       └─ MatchEnricherService::enrichWithApiFootballOdds → colonnes odds_* de matches

/analysis → bouton Analyser → MatchAnalysisController::analyzeExistingMatch
  ├─ ContextEnricherService::enrich                  fatigue, enjeux, météo
  │                                                  (stocké, ne nourrit pas le modèle)
  ├─ XGModelService::predict                         estimation des λ
  │    └─ PoissonModelService::fullAnalysis          matrice de scores
  └─ PredictionService                               → table predictions
```

Dix lignes par match : 1, X, 2, 1X, X2, 12, Over 2.5, Under 2.5, BTTS Oui,
BTTS Non.

---

## Le modèle

`XGModelService` estime deux paramètres, λ domicile et λ extérieur, en fusionnant
quatre signaux :

| Signal | Poids | Source |
|---|---|---|
| Marché | 0.40 | Recherche sur grille inversant les cotes 1X2, puis calage sur Over/Under 2.5 |
| Comparaison | 0.30 | Bloc `comparison` des prédictions API-Football |
| Pseudo-xG | 0.20 | `footystats_data`, dérivé du pourcentage de victoire API-Football |
| Blessures | multiplicatif | Nombre de joueurs absents, plancher 0.7 |

Puis avantage domicile (×1.08 / ×0.952) et bornage des λ.

`PoissonModelService` construit une matrice 7×7 de scores en supposant les deux
lois indépendantes, et en dérive les probabilités de chaque marché.

**Limite connue** : le signal comparaison est lui-même dérivé d'un Poisson sur
cotes. Il est donc largement redondant avec le signal marché. Le poids réel du
marché dépasse les 40 % affichés.

---

## Table `predictions`

| Colonne | Contenu |
|---|---|
| `market`, `outcome` | Marché et issue |
| `model_probability` | Probabilité du modèle, décimale 0 à 1 |
| `odds` | Cote du bookmaker unique |
| `implied_probability` | `1 / cote`, marge incluse. Le seuil réel à battre. |
| `fair_probability` | Après retrait de la marge, par normalisation de l'ensemble de marché |
| `edge` | `model_probability − fair_probability` |
| `bookmaker` | Source de la cote. `legacy_max` = maximum multi-bookmakers, inexploitable. |
| `odds_taken_at`, `computed_at` | Horodatages |

---

## Services actifs

| Service | Rôle |
|---|---|
| `Probability/XGModelService` | Estimation des λ |
| `Probability/PoissonModelService` | Matrice de scores et probabilités |
| `PredictionService` | Persistance des probabilités |
| `DataPipeline/MatchEnricherService` | Normalisation API-Football → base |
| `Api/ApiFootballService` | Fixtures, stats, cotes |
| `Api/OddsApiService` | Snapshots pour le CLV **uniquement** |
| `Api/WeatherService` | Météo |
| `Context/ContextEnricherService` | Fatigue, enjeux, météo, pression coach |
| `Market/CLVTrackerService` | Snapshots de cotes, écart de clôture |
| `Backtesting/FootballData/CsvParser` | CSV football-data → lignes normalisées (liste blanche de colonnes) |
| `Backtesting/FootballData/CalibrationBacktestService` | Backtest de calibration du modèle de production, mode marché seul |
| `Backtesting/FootballData/CalibrationAggregator` | Brier, calibration par tranche, segmentation |
| `Backtesting/BacktestEngine` | **Faux, remplacé par le backtest football-data ; à supprimer une fois le nouveau validé** |

## Débranché, code conservé

Les 5 agents IA et `ClaudeClient`, `ai:batch`, `ai:test`,
`ComboSelectorService`, `ComboBuilderService`, `GenerateDailyCombosJob`.
Ils sortent proprement si on les invoque. Rien ne les appelle.

## Supprimé

Sources manuelles A/B/C, Source E, `AnalyzerService`, `CalculatorService`,
`ValueAnalyzer`, `RulesService`, tout le Layer 2, `ParserService`,
`DataImportService`, `config/reliability.php`, `config/analyzer.php`,
`routes/api.php`, le formulaire de saisie manuelle.

---

## Tables

**Actives** : `matches`, `advanced_data`, `predictions`, `odds_movements`,
`referees`, `historical_matches`, `backtest_fd_runs`, `backtest_fd_predictions`.

**Conservées mais orphelines** : `recommendations`, `sources`,
`match_validations`, `combos`, `ai_analysis`, `daily_combos`, `backtest_runs`,
`backtest_predictions`. Elles contiennent des données historiques
irremplaçables. Ne pas supprimer.

**Colonnes mortes sur `matches`** : `global_confidence`, `layer1_score`,
`layer2_score`, `convergence`, `context`, `sources_data`.

---

## Le backtest de calibration (étape 2)

```
football-data:import --seasons=2324 --divisions=E0,D1
  └─ zip https://www.football-data.co.uk/mmz4281/{saison}/data.zip
       → storage/app/private/football-data/{saison}/
       → CsvParser (config/football-data.php : liste blanche, jamais Max/Avg)
       → table historical_matches

backtest:run --sample=work|holdout --seasons= --divisions= --input=b365|ps
  └─ CalibrationBacktestService::run
       ├─ pour chaque match : FootballMatch non persisté, cotes d'OUVERTURE seulement
       ├─ XGModelService::predict($match, marketOnly: true)      passe complète (1X2 + O/U 2.5)
       │    → famille adjustment (1X2, O/U 2.5) + famille derived (DC, BTTS, O/U 1.5, O/U 3.5)
       ├─ XGModelService::predict($match, marketOnly: true)      passe transfert (1X2 seul)
       │    → famille transfer (O/U 2.5 comparé au marché réel)
       ├─ CalibrationAggregator                                   Brier, MSE vs Pinnacle clôture,
       │                                                          tranches de 5 pts (n, moyenne, observé)
       ├─ table backtest_fd_predictions                           une ligne par match × famille × marché × issue
       └─ backtest_fd_runs + storage/app/private/backtest/run_{id}_{label}.json
```

La clôture Pinnacle démarginalisée est la référence et n'entre jamais dans le
modèle. Aucune mise, aucun ROI. Une cote absente exclut la ligne et se compte
par cause (`missing_score`, `missing_input_1x2`, `missing_input_ou25`,
`missing_pinnacle_close_1x2`, `missing_pinnacle_close_ou25`).

Coût : 0,08 s par `predict`, deux passes par match, soit environ 4 minutes pour
1 750 matchs et 1 h 45 pour les cinq saisons complètes.

---

## Pièges connus

- Les 960 matchs importés par `pipeline:backfill` en 2026 contiennent des données
  collectées **après** le coup d'envoi : classement du moment de l'import,
  compositions réelles, statistiques à jour. Toute évaluation de features faite
  sur eux est invalide. Seuls les scores finaux sont fiables.
- Les cotes de ces mêmes matchs sont des maximums multi-bookmakers, étiquetés
  `legacy_max`. Inexploitables pour mesurer un écart.
- `env()` est appelé hors config à plusieurs endroits (`app/helpers.php`,
  `WeatherService`, `FetchMatchDataJob`, `routes/web.php`). `config:cache`
  casserait ces appels. Ne pas l'activer sans corriger d'abord.
- Les compositions d'équipe sont rarement disponibles avant le coup d'envoi.
- Dans `CalibrationBacktestService`, les issues `'1'` et `'2'` deviennent des
  entiers quand elles servent de clé de tableau PHP : toujours les recaster en
  chaîne avant comparaison stricte (bug rencontré et corrigé en validation).
- `pdo_sqlite` est absent de la machine de développement : les tests Breeze
  échouent pour cette raison, sans rapport avec le code métier.
