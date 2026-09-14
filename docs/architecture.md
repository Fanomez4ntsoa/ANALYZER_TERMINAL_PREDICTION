# Architecture

État au 14/09/2026, après le nettoyage qui suit l'étape 2. Décrit ce qui existe
réellement, pas ce qui est prévu.

---

## Le chemin d'une prédiction

```
Planificateur (routes/console.php, cron `schedule:run` requis)
  ├─ pipeline:daily, chaque jour à config pipeline.schedule_time (10:00 UTC)
  │    pipeline:run-sync → context:enrich → predictions:compute → market:track snapshot
  │    chaque étape journalisée dans storage/logs/pipeline-*.log
  │    import en exception = arrêt ; import incomplet (code non nul) = étapes suivantes lancées
  └─ toutes les 5 min : market:track closing, puis market:track close

pipeline:run-sync {date}          code de sortie non nul si cotes ou scores incomplets
  └─ FetchMatchDataJob              ordre imposé par le budget : indispensable d'abord
       │  match commencé, reporté ou terminé : score seul, rien d'autre n'est écrit
       ├─ ApiFootballService::getDailyUsage           /status, budget du jour (non décompté)
       ├─ ApiFootballService::getFixturesByDate       1 requête → upsert dans matches
       ├─ ApiFootballService::getFixtureOdds          /odds?fixture=&bookmaker=8, 1 requête par match
       │    └─ enrichWithApiFootballOdds               odds_* + odds_fetched_at + odds_bookmaker
       │       budget insuffisant : avertissement, matchs non couverts listés, passage incomplet
       │       cotes incomplètes : facultatif non collecté (budget gardé pour une relance)
       ├─ scores de la veille                         1 requête au plus
       ├─ ApiFootballService::getOptionalMatchData    prédictions + blessures, 2 requêtes/match,
       │    └─ enrichWithAdvancedData                  abandonné sous api-football.budget.optional_reserve
       └─ FetchOddsJob                                liaison The Odds API, championnats du CLV seulement

Toute erreur API-Football (débit, quota, refus de l'offre, HTTP, réseau) lève
ApiFootballException : jamais confondue avec une absence de donnée. Appels espacés
de 6,5 s (10/minute), une seule nouvelle tentative après un 429.

/analysis → bouton Analyser → MatchAnalysisController::analyzeExistingMatch
  (ou predictions:compute {date} : matchs à venir, non contaminés, avec cotes 1X2)
  ├─ ContextEnricherService::enrich                  fatigue, enjeux, météo, pression
  │                                                  (stocké, ne nourrit pas le modèle ;
  │                                                  jamais après le coup d'envoi)
  ├─ XGModelService::predict                         estimation des λ
  │    └─ PoissonModelService::fullAnalysis          matrice de scores
  └─ PredictionService                               → table predictions
```

Dix lignes par match : 1, X, 2, 1X, X2, 12, Over 2.5, Under 2.5, BTTS Oui,
BTTS Non.

---

## Le modèle

`XGModelService` estime deux paramètres, λ domicile et λ extérieur, en fusionnant
trois signaux :

| Signal | Poids | Source |
|---|---|---|
| Marché | 0.40 | Recherche sur grille inversant les cotes 1X2, puis calage sur Over/Under 2.5 |
| Comparaison | 0.30 | Bloc `comparison` des prédictions API-Football |
| Blessures | multiplicatif | Nombre de joueurs absents, plancher 0.7 |

L'avantage domicile (×1.20 / ×0.88) s'applique au seul signal comparaison, avant
la fusion : les cotes le contiennent déjà. En mode marché seul il
ne s'applique pas. Puis bornage des λ.

`PoissonModelService` construit une matrice 7×7 de scores en supposant les deux
lois indépendantes, et en dérive les probabilités de chaque marché.

Correction de Dixon-Coles sur les scores faibles (0-0, 1-0, 0-1, 1-1), active par
défaut : ρ unique sur toutes les divisions, estimé par `DixonColesRho` sur les
scores observés des saisons de travail (strictement antérieures au match en
backtest). Un ρ par population reste disponible (`dixon_coles_rho_scope`). Sans estimation,
deux lois indépendantes. Jusqu'au 13/09/2026, le code citait Dixon-Coles sans
l'implémenter ; `XGModelService` attribue toujours à des « études Dixon-Coles » les
constantes d'avantage domicile ×1.20 / ×0.88, sans source vérifiable.

Signal marché, avec cotes Over/Under : total fixé par l'O/U, puis partage
domicile-extérieur recherché sur le 1X2 à ce total (recalage conjoint). Sans O/U :
grille sur (λh, λa), total libre.

Tout paramètre estimé sur `historical_matches` implémente `SeasonScopedEstimator` :
en backtest, il n'est estimé que sur les saisons strictement antérieures au match.

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
| `bookmaker` | Bookmaker unique de la cote, repris de `matches.odds_bookmaker` (bet365). `legacy_max` = maximum multi-bookmakers, inexploitable. |
| `odds_taken_at`, `computed_at` | Horodatages |

---

## Services actifs

| Service | Rôle |
|---|---|
| `Probability/XGModelService` | Estimation des λ |
| `Probability/PoissonModelService` | Matrice de scores et probabilités |
| `Probability/DixonColesRho` | ρ de Dixon-Coles par population, maximum de vraisemblance sur les scores (saisons antérieures) |
| `Probability/LeagueGoalAverages` | Buts par match par championnat, saisons de travail (ancre du total sans O/U) |
| `PredictionService` | Persistance des probabilités |
| `DataPipeline/MatchEnricherService` | Normalisation API-Football → base ; `isBeforeKickoff` |
| `DataPipeline/PipelineLog` | Avertissement pour toute exception interceptée dans le pipeline |
| `Api/ApiFootballService` | Fixtures, cotes Bet365 par match, prédictions et blessures ; `ApiFootballException` ; budget du jour |
| `Api/OddsApiService` | Snapshots Pinnacle pour le CLV **uniquement** |
| `Api/WeatherService` | Météo |
| `Context/ContextEnricherService` | Fatigue, enjeux, météo, pression coach |
| `Market/CLVTrackerService` | Snapshots de cotes, snapshot de clôture sans cache, écart de clôture |
| `Backtesting/FootballData/CsvParser` | CSV football-data → lignes normalisées (liste blanche de colonnes) |
| `Backtesting/FootballData/TeamNameAudit` | Graphies d'équipes qui ne diffèrent que par un caractère non-ASCII |
| `Backtesting/FootballData/CalibrationBacktestService` | Backtest de calibration du modèle de production, mode marché seul |
| `Backtesting/FootballData/CalibrationAggregator` | Brier, calibration par tranche, segmentation |

## Supprimé

Étape 1 : sources manuelles A/B/C, Source E, `AnalyzerService`, `CalculatorService`,
`ValueAnalyzer`, `RulesService`, tout le Layer 2, `ParserService`,
`DataImportService`, `config/reliability.php`, `config/analyzer.php`,
`routes/api.php`, le formulaire de saisie manuelle.

14/09/2026 (code conservé par le tag `etape-2-terminee`) : les 5 agents IA et
`ClaudeClient`, `ai:batch`, `ai:test`, `results:collect`, `ComboSelectorService`,
`ComboBuilderService`, `GenerateDailyCombosJob`, page `/combos`, `BacktestEngine`,
`BacktestController`, page `/backtest`, signal `xg_proxy`, dimension arbitre du
contexte, modèles `AIAnalysis`, `DailyCombo`, `BacktestRun`, `BacktestPrediction`,
`Referee`.

## Planificateur et clôture

**Deux bookmakers, deux usages.** Les prédictions utilisent Bet365 via API-Football
(`api-football.preferred_bookmaker`). Le CLV utilise Pinnacle via The Odds API
(`odds-api.clv_bookmaker`) : **il mesure le mouvement de Pinnacle entre la prédiction
et la clôture, pas celui du prix Bet365.** Bet365 n'existe pas sur The Odds API.
Chaque snapshot enregistre son bookmaker (`odds_movements.bookmaker`) ; cote de
prédiction et clôture viennent du même bookmaker, sinon pas de clôture. Pinnacle ne
publie en `totals` que sa ligne principale (2.5 sur 18 événements sur 81 le
14/09/2026) : le CLV Over 2.5 est partiel, le CLV 1X2 complet.

`config/pipeline.php` : heure et fuseau de `pipeline:daily`, créneau horaire des
matchs, clôture (`window_minutes` 10, `leagues` Top 5, `quota_reserve` 50).

- `market:track closing` : matchs non contaminés dont le coup d'envoi tombe dans
  la fenêtre, déjà dotés d'un premier snapshot, sans snapshot dans la fenêtre. Un
  appel The Odds API sans cache par championnat (h2h + totals, 2 crédits).
- `market:track close` : cote de clôture = dernier snapshot pris avant le coup
  d'envoi et dans la fenêtre. Sinon clôture vide, signalée dans le journal. Aucun
  repli sur les cotes `odds_*` de `matches` (API-Football).

---

## Tables

**Actives** : `matches`, `advanced_data`, `predictions`, `odds_movements`,
`historical_matches`, `backtest_fd_runs`, `backtest_fd_predictions`.

**Conservées mais orphelines** : `recommendations`, `sources`,
`match_validations`, `combos`, `ai_analysis`, `daily_combos`, `backtest_runs`,
`backtest_predictions`, `referees`. Elles contiennent des données historiques
irremplaçables. Ne pas supprimer.

**Colonnes mortes sur `matches`** : `global_confidence`, `layer1_score`,
`layer2_score`, `convergence`, `context`, `sources_data`. Sur `advanced_data` :
`footystats_data` (plus écrite).

**`matches.post_kickoff_data`** : une donnée du match a été écrite après son coup
d'envoi. Exclu de toute mesure : toute requête de mesure sur `matches` passe par
le scope `measurable()`. Posé à la création d'un match dont le coup d'envoi est
passé, jamais retiré.

---

## Le backtest de calibration (étape 2)

```
football-data:import --seasons=2324 --divisions=E0,D1 [--force-download]
  └─ zip https://www.football-data.co.uk/mmz4281/{saison}/data.zip
       → storage/app/private/football-data/{saison}/data.zip (jamais retéléchargé s'il existe ;
         des CSV déjà décompressés dans {saison}/csv/ ou {saison}/ suffisent)
       → CsvParser (config/football-data.php : liste blanche, jamais Max/Avg ;
         chaque ligne testée : UTF-8 gardé, sinon converti depuis Windows-1252)
       → table historical_matches
       → TeamNameAudit : noms distincts par division, paires ne différant que par un
         caractère non-ASCII signalées

backtest:run --sample=work|holdout --seasons= --divisions= --input=b365|ps [--legacy-home] [--legacy-share] [--legacy-poisson] [--rho-per-population] [--anchor]
  │    (config/xg-model.php : facteur domicile hors signal marché, recalage conjoint, ancrage désactivé ;
  │     --legacy-* rétablissent l'ancien comportement, --anchor active l'ancrage, pour la mesure)
  │    estimateurs tagués SeasonScopedEstimator bornés à chaque match aux saisons antérieures
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

backtest:report {run} [--compare={run}] [--seasons=]
  └─ lecture par population (config football-data.populations), jamais 22 divisions confondues
       → ρ estimés, Brier modèle / entrée démarginalisée / Pinnacle clôture, annoncée vs observée,
         biais domicile, décalage Over en transfert, segmentation par division
       → avec --compare : écarts par population et écart apparié sur les matchs communs (erreur type, z)
       → storage/app/private/backtest/run_{id}_{label}_populations.md
```

La clôture Pinnacle démarginalisée est la référence et n'entre jamais dans le
modèle. Aucune mise, aucun ROI. Une cote absente exclut la ligne et se compte
par cause (`missing_score`, `missing_input_1x2`, `missing_input_ou25`,
`missing_pinnacle_close_1x2`, `missing_pinnacle_close_ou25`).

Coût : 0,08 s par `predict`, deux passes par match, soit environ 4 minutes pour
1 750 matchs et 1 h 45 pour les cinq saisons complètes.

---

## Limites de l'offre gratuite

API-Football gratuit : **100 requêtes par jour** (remises à zéro à minuit UTC),
**10 par minute**. Refusés pour la saison en cours : `standings`, `teams/statistics`,
`/odds?league=&season=`, et le paramètre `last` de `headtohead`.

**Limite non documentée, constatée le 14/09/2026 : le paramètre `page` est plafonné
à 3.** `/odds?date=` renvoyait 13 pages (tous championnats du monde) ; la page 4 est
refusée (`Free plans are limited to a maximum value of 3 for the Page parameter`).
L'appel par date est donc inutilisable : il ne couvre que les 30 premiers matchs
mondiaux, et aucun des 11 matchs suivis ce jour-là n'y figurait. Les cotes se
relèvent match par match (`/odds?fixture=`, une page).

**Compteurs de l'API en retard.** Juste après un passage de 34 requêtes, `/status` en
comptait 18 et l'en-tête `x-ratelimit-requests-remaining` 25 ; `/status` n'a rattrapé
qu'après quelques secondes. Le budget est estimé au plus pessimiste de `/status`, de
l'en-tête et d'un compteur local par jour UTC.

The Odds API gratuit : **500 crédits par mois**.

### Coût mesuré d'un passage quotidien (14/09/2026, cache vidé)

| Poste | Requêtes | 11 matchs |
|---|---|---|
| `/status` | 0 (non décompté) | 0 |
| Matchs de la date | 1 | 1 |
| Cotes, 1 par match | N | 11 |
| Scores de la veille | 0 ou 1 | 0 |
| Facultatif (prédictions + blessures), 2 par match | 2N | 22 |
| **Total** | **≈ 3N + 2** | **34 mesurées** |

Durée : 239 s (appels espacés de 6,5 s).

### Projection

| Journée | Matchs | Indispensable (cotes + fixtures + veille) | Facultatif possible (réserve 10) | Reste pour une relance |
|---|---|---|---|---|
| Lundi 14/09/2026 | 11 | 13 | 11 matchs sur 11 | environ 55 |
| Samedi type | 50 | 52 | 19 matchs sur 50 | 0 à 10 |
| Samedi 02/05/2026 observé | 66 | 68 | 11 matchs sur 66 | 0 à 10 |
| Top 5 seul, jour le plus chargé observé | 21 | 23 | 21 matchs sur 21 | environ 30 |

- Avec les 21 ligues suivies, **un samedi consomme à lui seul plus de la moitié du
  quota en cotes**, et le budget entier une fois le facultatif ajouté. Le garde-fou
  garde les cotes et abandonne le facultatif, mais **il ne reste rien pour relancer
  un passage échoué** ni pour une analyse manuelle : un samedi raté est perdu.
- Le filtre horaire 12h-21h UTC réduit déjà ces volumes ; le lever (condition pour
  une fatigue disponible) les augmenterait.
- The Odds API : clôture limitée au Top 5 (environ 260 crédits/mois) et snapshot du
  jour aux mêmes championnats (2 crédits par championnat ayant un match). Marge faible
  sur 500 crédits, à surveiller dans le journal.

**Décision à prendre plus tard**, documentée ici pour ne pas être oubliée :

1. **Offre supérieure** API-Football (et éventuellement The Odds API) : toutes les
   ligues suivies, facultatif compris, relances possibles, et les dimensions de
   contexte bloquées (enjeux, pression) redeviennent collectables.
2. **Périmètre restreint** à quelques championnats : le coût suit le périmètre
   (environ 3 requêtes par match). Le Top 5 seul tient dans l'offre gratuite avec la
   marge d'une relance.

## Pièges connus

- 913 matchs contiennent des données collectées **après** le coup d'envoi (791
  créés par le backfill du 08/04/2026, 25 créés après coup d'autres jours, 97
  réécrits par des relances du pipeline), marqués `post_kickoff_data`. Toute évaluation de features faite sur
  eux est invalide. Seuls les scores finaux sont fiables.
- Les cotes de ces mêmes matchs sont des maximums multi-bookmakers, étiquetés
  `legacy_max`. Inexploitables pour mesurer un écart.
- `env()` n'est appelé que dans `config/` : `config:cache` fonctionne (vérifié le
  14/09/2026). Ne pas en réintroduire ailleurs.
- Le quota The Odds API (500 crédits/mois) ne couvre pas la clôture de tous les
  championnats suivis : voir `pipeline.closing.leagues`.
- Les compositions d'équipe ne sont pas collectées : jamais publiées à l'heure du
  passage quotidien.
- Contexte : enjeux et pression entraîneur toujours indisponibles avec l'offre
  gratuite ; fatigue indisponible tant que l'import filtre par créneau horaire.
- Dans `CalibrationBacktestService`, les issues `'1'` et `'2'` deviennent des
  entiers quand elles servent de clé de tableau PHP : toujours les recaster en
  chaîne avant comparaison stricte (bug rencontré et corrigé en validation).
- `pdo_sqlite` est absent de la machine de développement : les tests Breeze
  échouent pour cette raison, sans rapport avec le code métier.
