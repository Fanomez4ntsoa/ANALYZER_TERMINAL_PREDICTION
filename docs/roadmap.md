# Feuille de route

---

## Étape 1 — Simplification ✅ 13/09/2026

Le système sort des probabilités, plus de verdicts. 12 116 lignes supprimées,
637 ajoutées. Contrôleur de 1635 à 345 lignes.

Inclut le passage à une cote unique de bookmaker jouable.

---

## Étape 1b — Correctifs d'intégrité ✅ 13/09/2026

- `legacy_max` sur les cotes des matchs importés avant le commit c6f76e2
  (frontière : `matches.odds_fetched_at` nul)
- The Odds API n'écrit plus dans `odds_*` : `FetchOddsJob` ne stocke que
  `odds_api_event_id` pour le CLV
- Colonne `odds_fetched_at` sur `matches`, lue par `predictions.odds_taken_at`
- `ComboSelectorService` et `GenerateDailyCombosJob` sortent avec un message

---

## Étape 2 — Backtest honnête ✅ 13/09/2026 (branche `feat/backtest-football-data`)

**L'étape décisive.** Rien de structurant ne se décide avant ses résultats.

Fait le 13/09/2026 :

- `football-data:import` : cinq saisons (2122 à 2526), 22 divisions par zip,
  table `historical_matches`. Bet365 et Pinnacle, ouverture et clôture, 1X2 et
  O/U 2.5. Jamais Max/Avg. Encodage détecté ligne par ligne (Windows-1252 ou
  UTF-8 selon le fichier), zip jamais retéléchargé sans `--force-download`,
  CSV déjà décompressés acceptés, audit des graphies d'équipes en fin d'import.
- Migrations `2026_09_13_000003` et `2026_09_13_000004` exécutées, import
  complet réalisé : 38 780 matchs (7 822 / 7 830 / 7 800 / 7 681 / 7 647 par
  saison de 2122 à 2526), aucune colonne de la liste blanche absente, 44 lignes
  converties depuis Windows-1252 (toutes dans `2122/EC.csv`), aucune graphie
  d'équipe en double. `2122/EC.csv` ne contient que 506 matchs sur 552 à la
  source.
- `XGModelService::predict(marketOnly: true)` : le modèle de production, signal
  marché seul, sans version simplifiée.
- `CalibrationBacktestService` + `backtest:run` : trois familles (ajustement,
  dérivés, transfert), cotes d'ouverture en entrée, clôture Pinnacle
  démarginalisée en référence, Brier et calibration par tranche de 5 points,
  exclusions comptées. Saisons 2425 et 2526 réservées (`--sample=holdout`).
- Passe de validation en mémoire, 2023/24, E0 + D1 + I1 + SP1 + F1, 1752 matchs
  (`storage/app/private/backtest/validation_2324_E0-D1-I1-SP1-F1_in-memory.json`) :
  - 1X2 : biais domicile +5,9 pts (modèle 49,0 %, observé 43,1 %, Pinnacle 44,5 %),
    Brier 0,1906 contre 0,1886 pour Pinnacle clôture
  - O/U 2.5 en ajustement : identique au bookmaker, comme attendu (contrôle)
  - Transfert 1X2 → O/U 2.5 : Over sous-estimé de 6 pts, Brier 0,2414 contre 0,2337
  - BTTS, O/U 1.5, O/U 3.5 (tests indépendants) : moyennes à moins de 3 pts de
    l'observé, Brier 0,242 / 0,163 / 0,211

- Run #2 (`backtest:run --sample=work --input=b365`, 22 divisions, 23 388 matchs
  évalués, 64 exclus faute de cote Bet365 d'ouverture), lu par population
  (`storage/app/private/backtest/run_2_work-b365-22div_populations.md`) :
  - Biais domicile 1X2 uniforme : +4,9 (Top 5), +5,3 (deuxièmes divisions),
    +4,7 (inférieures). Pinnacle clôture : 0 dans les trois. Décalage constant
    modèle − cote d'entrée de +4,6 à +5,6 dans les dix divisions majeures.
  - Transfert Over 2.5 uniforme : −5,3 / −5,9 / −5,5, plus fort dans les
    championnats à buts (D2 −10,6, D1 et E0 −6,7) que dans les faibles
    (SP1 −3,4, SP2 −3,8).
  - Brier 1X2 modèle contre Pinnacle clôture : 0,1936 / 0,1919 (Top 5),
    0,2104 / 0,2079 (deuxièmes), 0,1974 / 0,1950 (inférieures).
  - BTTS, O/U 1.5, O/U 3.5 : moins de 3 points d'écart partout ; BTTS Oui
    sous-estimé de 2,6 points dans le Top 5.

- `backtest:report {run} [--compare=]` : rapport par population en commande
  (`config/football-data.php` : `populations`).
- Corrections du modèle (`config/xg-model.php`), mesurées une par une sur
  l'échantillon de travail, Bet365 ouverture, 23 388 matchs par run :
  - Run #4, avantage domicile hors signal marché : biais domicile +4,9 / +5,3 /
    +4,7 → +1,0 / +1,3 / +0,8, Brier 1X2 égal à l'entrée à 0,0003 près et
    au-dessus de Pinnacle. Retenue.
  - Run #5, ancrage du total seul : 1X2 inchangé, décalage Over en transfert
    effacé en moyenne.
  - Run #3, les deux : 1X2 identique au run #4, décalage Over +0,3 / −0,7 / +0,1,
    mais mesure en échantillon. Hors échantillon (ancre des saisons antérieures,
    2223-2324) : Brier transfert pire dans le Top 5, meilleur dans les deuxièmes
    divisions, neutre ailleurs. Ancrage non retenu en l'état.
  - BTTS Oui du Top 5 : −2,6 → −3,0. Pas lié au biais domicile.

- Décisions du 13/09/2026 : ancrage désactivé par défaut ; recalage conjoint
  total/partage ; règle générale des estimateurs bornés aux saisons strictement
  antérieures (`SeasonScopedEstimator`).

- Run #6, recalage conjoint : biais domicile +0,6 / +0,5 / +0,2, nul toujours
  sous-estimé de 2,2 / 2,1 / 1,2 points, Brier 1X2 au-dessus de Bet365 ouverture
  (+0,0005 / +0,0004 / +0,0002) et de Pinnacle. Transfert inchangé (−6,7 / −7,3 /
  −6,8 sur l'Over). Conservé par défaut.

- Ordre revu le 13/09/2026 : Dixon-Coles avant le facteur multiplicatif. La cause
  du résidu est l'hypothèse d'indépendance, pas le partage ni le total.
- Dixon-Coles implémenté (`DixonColesRho`, `config/xg-model.php`) : ρ estimé par
  maximum de vraisemblance sur les scores, par population, sur les saisons
  strictement antérieures au match.

- Run #7, Dixon-Coles (lecture 2223-2324) : nul au niveau de la cote d'entrée
  (−0,3 / −0,1 / −0,4), Over en transfert −1,7 / −0,2 / −2,4 au lieu de −7,
  Brier 1X2 égal à Bet365 ouverture et au-dessus de Pinnacle, Brier transfert
  à mi-chemin de Pinnacle. Retenu par défaut.
- **Facteur multiplicatif par championnat : abandonné** (règle fixée avant le
  run #7, condition remplie).

- Run #8, ρ unique sur toutes les divisions : aucune dégradation de plus de deux
  erreurs types dans aucune population. Retenu par défaut.
- Run #9, entrée Pinnacle ouverture contre Bet365 ouverture : Brier 1X2 plus bas de
  0,00005 / 0,00014 / 0,00013 (z −0,8 / −2,0 / −1,8), soit la moitié de l'écart
  entre les deux cotes d'entrée ; gain net seulement sur le transfert des divisions
  inférieures. Sensibilité à la source faible.

**Modèle en fin d'étape** : avantage domicile hors signal marché, recalage conjoint
total/partage, Dixon-Coles à ρ unique estimé sur les saisons antérieures, ancrage
désactivé. Sur 2223-2324, Brier 1X2 égal à celui de la cote d'entrée et au-dessus
de Pinnacle clôture ; marchés dérivés à moins de 2 points de l'observé, sauf le
BTTS Oui du Top 5 (−2).

Laissé ouvert :

- BTTS Oui du Top 5 sous-estimé de 2 points : non expliqué, à surveiller
- Résidu de l'Over en transfert dans les divisions à buts : observation non
  exploitée (`docs/decisions.md`)
- Échantillon réservé : non touché, gardé pour le jalon unique de fin d'étape 4

Questions auxquelles cette étape doit répondre : le Poisson sur cotes est-il
calibré, sur quels marchés, sur quels championnats, et bat-il la clôture ?

---

## Étape 2b — Nettoyage avant l'interface ✅ 14/09/2026 (branche `chore/nettoyage-post-etape-2`)

- Supprimés (code conservé par le tag `etape-2-terminee`, tables gardées) : les 5
  agents IA, `ClaudeClient`, `ai:batch`, `ai:test`, `results:collect`, les
  combinés et la page `/combos`, l'ancien `BacktestEngine` et la page `/backtest`.
- Retirés : signal `xg_proxy`, dimension arbitre du contexte.
- Corrigé : appel à `FormationProfiles` (supprimée à l'étape 1) qui bloquait les
  cotes dès qu'une composition était publiée ; exceptions avalées désormais
  journalisées (canal `pipeline`) ; plus aucune écriture sur un match commencé,
  hormis le score ; `env()` hors config remplacés, `config:cache` vérifié.
- Matchs contaminés : `matches.post_kickoff_data`, 913 matchs sur 1 140, exclus
  de toute mesure (`measurable()`).
- `predictions:compute {date?}`, planificateur réactivé (`pipeline:daily` à
  10:00 UTC), clôture automatique du CLV (Top 5, fenêtre de 10 minutes).

Reste à faire par l'utilisateur : lancer la migration
`2026_09_14_000001_add_post_kickoff_data_to_matches_table`, ajouter la ligne cron
`schedule:run`, passer `PIPELINE_SCHEDULE_TIME` à 10:00 dans `.env`.

### Constats du premier passage réel (14/09/2026)

- **Cotes : 9 matchs sur 11 sans cote, cause = limite de débit API-Football**
  (offre gratuite : 10 requêtes/minute, 100/jour). Environ 9 appels par match, dont
  4 refusés d'office par l'offre (`headtohead` avec `last`, `teams/statistics` et
  `standings` sur la saison 2026). À partir du 3e match, HTTP 429 ; `request()`
  renvoie null et le pipeline compte « sans cotes », 0 échec. Bet365 est présent
  sur les 11 matchs à l'appel direct, une seule page par `/odds?fixture=`.
  `/odds?date=` pagine (13 pages de 10 ce jour-là).
- **CLV : 0 snapshot.** `odds_api_event_id` est renseigné sur 10 matchs sur 11,
  mais Bet365 n'existe pas dans The Odds API : absent des 95 événements en région
  `eu`, et des régions `uk` et `us` (`au` non testée). Chaîne CLV inopérante tant
  que le bookmaker de référence y est Bet365.
- **Contexte collecté vide (à traiter plus tard, ne nourrit pas le modèle) :**
  - Fatigue 0/0 partout : comptée sur les seuls matchs présents en base (ligues
    suivies, créneau 12h-21h UTC), or rien n'a été importé entre le 05/05 et le
    14/09/2026. Même avec un import continu, coupes et matchs hors créneau
    manquent : la mesure reflète la couverture du pipeline, pas le calendrier
    réel. Et elle se déclare `available: true` avec des zéros.
  - Enjeux vides : classement (`standings`) refusé par l'offre gratuite pour
    2026, donc pas de `fbref_data`. En début de saison, le rang ne dit de toute
    façon presque rien.
  - Pression entraîneur vide : forme récente issue de `teams/statistics`,
    refusé pour 2026.
  - Météo : 6 matchs sur 11 inconnus, la ville est devinée par une table de noms
    d'équipes codée en dur.
  - Comparaison et blessures : 2 matchs sur 11, pour cause de limite de débit.

### Suites du diagnostic (14/09/2026)

- Corrigé : erreur d'API comptée comme échec ; cotes match par match, en premier et
  seules, avec garde-fou de budget (l'appel par date est inutilisable : l'offre gratuite
  plafonne `page` à 3) ; appels refusés par l'offre, `/fixtures?id=` et compositions
  retirés ; facultatif (prédictions, blessures) abandonné sous une réserve de budget et
  non collecté quand les cotes sont incomplètes.
- Relance vérifiée : 11 matchs sur 11 cotés Bet365, 34 requêtes. Un samedi à 50-66
  matchs consommait tout le quota sans marge de relance.
- **Tranché : cotes sur le Top 5** (`API_FOOTBALL_ODDS_LEAGUES`), matchs et scores sur
  les 21 ligues, matchs hors périmètre comptés chaque jour dans le journal. 23 requêtes
  au lieu de 68 le jour le plus chargé. Élargir avec une offre supérieure.
- Laissé ouvert : CLV Over 2.5 partiel (ligne principale Pinnacle à 2.5 sur environ un
  quart des événements), accepté ; le CLV 1X2 est complet.

Étape close le 14/09/2026 : fusion `--no-ff` dans `main`, tag `etape-2b-nettoyage`.
- CLV contre Pinnacle sur The Odds API, bookmaker enregistré par snapshot. CLV
  Over 2.5 partiel : Pinnacle ne publie la ligne 2.5 en `totals` que sur 18
  événements sur 81.
- Contexte : chaque dimension sans donnée se déclare indisponible. **Enjeux et
  pression entraîneur bloqués par l'offre gratuite** (`standings` et
  `teams/statistics` refusés pour la saison en cours) : pas un bug, rien à corriger
  tant que l'offre ne change pas. Fatigue indisponible tant que l'import filtre par
  créneau horaire.
- Volume d'appels et offre API-Football : décision à prendre plus tard, voir
  `docs/architecture.md`, section « Limites de l'offre gratuite ».

### Bookmaker de repli — non codé, en attente d'une absence observée

Bet365 était présent sur les 11 matchs du 14/09/2026 : on ne code pas pour un
problème non observé. Le journal le signale désormais (« bookmaker absent sur N
match(s) après lecture de toutes les pages »). Le jour où il le fera :

- une liste ordonnée de bookmakers dans la config (par exemple Bet365, puis Pinnacle,
  présent sur les 11 matchs de ce jour-là) ;
- tous les marchés d'un match viennent du premier bookmaker présent, jamais un
  mélange entre marchés, jamais un maximum ni une moyenne ;
- son nom va dans `matches.odds_bookmaker`, donc dans `predictions.bookmaker` ;
- le journal compte chaque jour les matchs où le premier choix manquait.

La règle à préserver est celle d'une cote d'un bookmaker unique et identifié, pas
celle de Bet365 en particulier.

---

## Étape 3 — Interface ✅ 14/09/2026 (branche `feat/terminal-ui`, tag `etape-3-terminee`)

Fait le 14/09/2026 :

- Prérequis : production en marché seul, `pipeline_runs`, calibration du run de
  référence.
- Système de design (`docs/design-system.md`), deux revues sur la page de
  démonstration : vidéo inverse unique, vert vif rationné, compteur de lueurs sur
  l'effet rendu, principe 3 (écart mécanique des marchés d'ajustement).
- Socle : jetons CSS, polices locales, entrée Vite du terminal, layout avec
  fraîcheur du pipeline et états système, composants Blade
  (`docs/architecture.md`).

- Signes de l'écart à poids égal (`--p-hot` / `--neg`), résidu d'ajustement
  documenté comme contrainte de marché non satisfaite.
- Page principale (`/dashboard`) : sélections et combiné, Monte-Carlo, calibration
  du run #8 sur 1X2, O/U 2.5 et BTTS, Brier, écart de clôture.

- Tri par heure, championnat, marché et filtre par marché ; tri par écart retiré.
- Pages matchs, historique, détail, marché, réglages et connexion migrées ; anciens
  gabarits et CDN supprimés.
- Correctif : le bouton de calcul écrivait des prédictions sur un match commencé
  (aucune ligne touchée en base, vérifié).

- Score et alerte « sharp money » supprimés (variations brutes conservées pour
  l'étape 4) ; route web de suppression d'un match supprimée.
- Tests Feature non exécutables sur cette machine : `pdo_sqlite` absent (23 tests sur
  24 échouent sur « could not find driver »). Le test par défaut, faux depuis
  l'initialisation (`/` redirige vers la connexion), est corrigé.

Reporté à l'étape 4 : sortir la DC de la famille « dérivés » du backtest.

Correctif du 14/09/2026 (branche `fix/clv-snapshots-fiables`) : relevés du CLV sans
cache, datés et contrôlés ; échec visible quand un relevé attendu manque ; quota
The Odds API lu sur l'API. Pinnacle absent de The Odds API depuis le 14/09 entre
06:19 et 11:40 UTC : un appel de contrôle (1 crédit) le 15/09 au matin.

Elle dépendait de l'étape 2, qui pouvait changer ce que le modèle produit. Le modèle
est maintenant stabilisé. L'interface affiche une probabilité, une cote et l'écart
entre les deux : ce format ne dépend ni des tests de l'étape 4 ni de la validation
sur l'échantillon réservé.

Maquette de référence validée : thème sombre phosphore, calibration en élément
principal, simulation Monte-Carlo animée, tout en une page sans défilement.
Prévoir un interrupteur d'animation dans l'interface plutôt qu'une obéissance
stricte au réglage système.

---

## Étape 4 — Information absente des cotes d'ouverture ⬜ à faire

Jusqu'ici on réparait le modèle. On cherche maintenant si une information absente
des cotes d'ouverture améliore la prédiction. Deux tests indépendants, qui peuvent
avancer en parallèle. Chacun est implémenté, mesuré et documenté pour lui-même.

### Règles communes aux deux tests

- **Prédiction écrite et commitée avant le run**, avec les seuils et les tranches
  fixés à l'avance. Aucune tranche choisie après avoir vu les résultats.
- **Échantillon de travail uniquement** (2122-2324). L'échantillon réservé n'est pas
  touché.
- **Un résultat négatif est un résultat qu'on garde** : il est documenté dans
  `docs/decisions.md` au même titre qu'un résultat positif.
- Tout poids, seuil ou coefficient estimé l'est sur des saisons strictement
  antérieures au match (`SeasonScopedEstimator`), jamais sur la saison évaluée.
- Lecture par population, comparaisons appariées sur les matchs communs avec
  erreur type (`backtest:report --compare`), jamais d'agrégat sur 22 divisions.
- Pas de meilleur segment : une division ou une tranche isolée pour son écart ne
  fonde aucune conclusion (voir l'observation non exploitée du 13/09/2026).
- Critère : calibration et Brier contre les résultats observés. Jamais de mise, de
  ROI ni de cote inventée.

### Test 1 — Mouvement de ligne

Les cotes de clôture Bet365 et Pinnacle sont déjà en base.

**Question.** L'amplitude du mouvement entre ouverture et clôture porte-t-elle une
information que l'ouverture ne contient pas ?

> **Avertissement : ce test est rétrospectif par nature.** En production, la clôture
> est inconnue au moment de parier. S'il donne un signal, le mouvement devient une
> **cible à prédire** à partir d'informations disponibles avant le coup d'envoi,
> **jamais une entrée à consommer**. Aucune donnée de clôture n'entre dans le modèle
> de production, conformément à la règle 5 de CLAUDE.md.

> **Relevés de production utilisables** : seulement `odds_movements.reliable = true`,
> c'est-à-dire postérieurs au 14/09/2026 (correction du cache). Les relevés antérieurs
> recyclaient des réponses en cache sous un horodatage récent et ajoutaient des
> variations nulles fictives.

Ce qu'on mesure, sur un même bookmaker de bout en bout pour ne pas mêler effet de
source et effet de temps :

- **Mouvement** : écart entre probabilité démarginalisée de clôture et d'ouverture,
  par issue, et son amplitude sans signe.
- **Calibration de l'ouverture selon l'amplitude** : Brier et écart annoncé −
  observé du modèle nourri à l'ouverture, par tranche d'amplitude fixée avant le
  run. Un mouvement fort signale-t-il une ouverture moins fiable ?
- **Contenu du sens du mouvement** : l'écart observé − annoncé à l'ouverture suit-il
  le sens du mouvement ? C'est attendu (la clôture intègre compositions et
  blessures), le chiffre utile est la part de l'écart qu'il explique.
- **Référence** : les résultats observés. La clôture Pinnacle ne peut pas servir de
  référence ici, puisqu'elle est l'une des bornes du mouvement mesuré.

### Test 2 — Désaccord entre bookmakers

Bet365 et Pinnacle à l'ouverture. Les runs #8 (Bet365) et #9 (Pinnacle), même
modèle, fournissent déjà les deux prédictions sur les mêmes matchs.

**Questions.** Quand ils divergent, lequel prédit mieux ? L'ampleur du désaccord
est-elle exploitable ?

Ce qu'on mesure :

- **Désaccord** : écart entre les probabilités démarginalisées Pinnacle et Bet365
  à l'ouverture, par issue, et son amplitude.
- **Qui prédit mieux quand ils divergent** : Brier apparié modèle Pinnacle − modèle
  Bet365 par tranche d'amplitude du désaccord, tranches fixées avant le run.
- **Exploitable** veut dire : une combinaison des deux sources, dont le poids est
  estimé sur les saisons antérieures et peut dépendre de l'amplitude du désaccord,
  améliore le Brier par rapport à la meilleure source seule, dans chaque population.
  Exploitable ne veut jamais dire rentable.
- **À vérifier avant le run** : l'heure de relevé des colonnes d'ouverture Bet365 et
  Pinnacle dans football-data. Un désaccord peut venir d'un décalage de relevé
  plutôt que d'une divergence d'opinion.

### Candidat pour un futur test des xG — noté le 14/09/2026, rien d'engagé

La bibliothèque Python EasySoccerData donne accès aux xG réels via son module FBref,
disponible uniquement dans sa version de développement. Candidat pour tester si les
xG apportent une information absente des cotes d'ouverture, **à condition de
construire un historique hors ligne** : ce sont des points d'accès non documentés,
donc inadaptés à une production quotidienne. Les mêmes règles s'appliquent
(prédiction écrite avant le run, saisons antérieures uniquement, xG d'un match
jamais utilisés avant son coup d'envoi).

### Jalon unique de fin d'étape 4 — validation sur l'échantillon réservé

Quand plus rien ne bouge dans le modèle, un passage unique de `--sample=holdout`
(2425-2526) valide l'ensemble des corrections d'un coup. Chaque passage sur
l'échantillon réservé l'use : on ne le lance pas avant.

---

## Étape 5 — Journal des sélections ✅ 15/09/2026 (branche `feat/prediction-log`)

Ce qui rend le système mesurable en conditions réelles, et la seule voie pour savoir
un jour si les signaux autres que le marché apportent quelque chose. Menée avant
l'étape 4 : l'enregistrement doit commencer tôt, l'effectif mettra des mois à venir.

Fait le 15/09/2026 (`docs/decisions.md`) :

- Table `prediction_log` : une ligne figée par prédiction jouable, à chaque calcul,
  dans la même transaction que `predictions`. Pipeline et bouton enregistrés, avec
  leur déclencheur. Modification refusée hors clôture, table préservée par
  `app:reset`, aucun remplissage rétroactif.
- `log:settle --date=` : issue sur le score final, jamais par défaut ; `closing_edge`
  (cote Bet365 × probabilité équitable Pinnacle à la clôture − 1), nul sans clôture
  fiable. Étape de `pipeline:daily` sur la veille, après `pipeline:run-sync`.
- `log:report [--market=] [--league=]` : premier calcul du pipeline seul ; Brier du
  modèle marché seul, du modèle complet (lignes à signal hors marché seulement) et de
  Bet365 équitable ; écart apparié à erreur type groupée par match ; tranches de 5
  points. Seuil de 200 matchs clôturés par marché, progression propre du modèle
  complet.
- Rien dans l'interface : on l'ajoutera quand il y aura de quoi montrer.

Migration lancée et 30 premières lignes enregistrées le 15/09/2026, puis perdues le
même jour avec toute la base (incident, `docs/decisions.md`). Le journal repart de zéro
le 15/09/2026. `log:report` refuse de tourner sans `config/prediction-log.php`.

Laissé ouvert :

- **Matchs AET/PEN sans score** : `MatchEnricherService` n'écrit le score que pour un
  statut FT, alors que `completed` est vrai aussi pour AET et PEN. Leurs lignes restent
  en attente et `log:settle` sort en échec. Problème connu depuis l'audit, non corrigé
  dans cette étape. Si beaucoup de matchs de coupe sont concernés, ce sera un trou
  permanent dans le journal. À corriger avec le score du temps réglementaire (les
  marchés du journal se règlent sur 90 minutes), jamais le score après prolongation.
- `closing_edge` : lire sa variation entre segments, jamais son signe absolu (écart de
  tarification Bet365 / Pinnacle).
- ~~Les lignes en attente des jours précédents sont signalées par `log:settle` mais pas
  reprises automatiquement.~~ Corrigé le 26/09/2026 : `log:settle` reprend toutes les
  dates en attente (voir plus bas).

---

## Incident du 15/09/2026 — base vidée, reconstruite (branche `fix/incident-base-videe`)

Base MariaDB vidée par la suite de tests lancée sur une configuration en cache
(`docs/decisions.md`). Fait le 15/09/2026 :

- Garde dans `tests/TestCase.php` : cache de configuration ou connexion non SQLite =
  suite refusée.
- `db:backup` : sauvegarde quotidienne, première étape de `pipeline:daily`, rotation
  sur sept jours, refusée quand la base a rétréci de moitié ; `db:backup --verify`
  restaure la dernière sauvegarde dans une base temporaire (vérifiée le 15/09/2026).
- Reconstruction : schéma, compte, `football-data:import` (38 780 matchs), backtest de
  référence relancé (run #1, Top 5, identique au run #8 par division),
  `football-data.reference_run` = 1. Aucun match passé réimporté.

Perdu définitivement : `recommendations` (235), `match_validations` (33), les matchs et
leurs cotes d'avant-match, les relevés Pinnacle, les 30 lignes du journal.

Reste à faire par l'utilisateur : ajouter la ligne cron `schedule:run` (aucune crontab
installée le 15/09/2026 : sans elle, ni `pipeline:daily`, ni la sauvegarde, ni la clôture
ne tournent ; systemd, cron et MariaDB sont déjà activés au démarrage de WSL, mais WSL
lui-même ne démarre pas avec Windows), changer le mot de passe du compte recréé.

---

## Correctif du 26/09/2026 — rattrapage des scores (branche `fix/rattrapage-scores`)

Trois matchs de Liga des 16 et 17/09 sans score, 30 lignes en attente : deux manqués
faute de passage le 18 et le 19, un reporté au 21/10 (`docs/decisions.md`). Fait :

- Rattrapage match par match (`/fixtures?id=`, seule voie avec l'offre gratuite
  au-delà de la veille), après les cotes et avant le facultatif, 20 requêtes au plus
  par passage, les plus récents d'abord, fenêtre de 60 jours.
- Lignes non clôturables (`prediction_log.void_reason`) : reprogrammé, annulé, arrêté,
  tapis vert, score indisponible au-delà de 60 jours. Comptées à part par `log:report`.
- `log:settle` clôture toutes les dates en attente jusqu'à la veille.
- L'appel de la veille ne crée plus de match contaminé.
- Match reprogrammé : cotes et CLV de l'ancien coup d'envoi remis à nul ; premier
  calcul mesuré par coup d'envoi.
- `log:report` affiche les jours sans passage du pipeline depuis le début du journal.

**À faire par l'utilisateur** : lancer la migration
`2026_09_26_000001_add_api_status_and_void_reason`, puis `pipeline:daily`. Attendu au
premier passage : 3 requêtes de rattrapage, Betis 1-0 Getafe et Málaga 1-3 Villarreal
clôturés (20 lignes), Levante-Athletic reprogrammé au 21/10 et ses 10 lignes déclarées
non clôturables. Installer la crontab reste la seule protection contre les jours perdus.

Laissé ouvert : `predictions` et `advanced_data` d'un match reprogrammé gardent les
valeurs de l'ancienne date jusqu'au prochain calcul et à la prochaine collecte du
facultatif (Levante-Athletic, 21/10/2026).

---

## Déploiement sur le VPS (branche `feat/deploiement-vps`)

Le VPS devient la base de référence et la seule machine qui collecte ; le portable ne
fait plus que lire des copies. Procédure complète : `docs/deploiement.md`. Fait le
26/09/2026 :

- Garde `PIPELINE_ENABLED` (défaut `false`) sur toutes les commandes de collecte,
  message qui dit pourquoi et quelle machine fait foi.
- `setup.sh` idempotent, qui vérifie sans rien installer (autre application en
  production sur le VPS) ; `deploy/env.vps.example` ; `user:create`.
- Transfert : `scripts/export-production.sh`, `scripts/import-production.sh`,
  `data:manifest`. Répété sur des bases temporaires : import conforme, relance refusée,
  dump à l'ancienne rejeté.
- **Décalage de fuseau de 3 h trouvé et corrigé** (`--skip-tz-utc`, `DB_TIMEZONE`).
- `backtest:reference` : reproduit le run #1 à l'identique sur une base neuve, 9 min 22.
- `system:status` et battement du planificateur.

Correctif du 27/09/2026 (branche `fix/timestamps-explicit-defaults`) : `migrate`
échouait sur le VPS (`explicit_defaults_for_timestamp` à 0, 1 sur le portable) et quatre
colonnes y recevaient un `ON UPDATE current_timestamp()` silencieux. Défaut explicite sur
les cinq `TIMESTAMP NOT NULL`, migration d'alignement, contrôle des réglages du serveur
et empreinte du schéma comparée au portable dans `setup.sh` (`docs/decisions.md`).

Correctif du 30/09/2026 (branche `fix/budget-api-football`) : zéro match du 27 au 29/09
expliqué par la trêve internationale, pas par un défaut. Budget API-Football au plus
pessimiste du corps et de l'en-tête de `/status` et du compteur local ; ligues 113, 119
et 103 réactivées (zéro requête de plus, hors périmètre des cotes) ;
`api-football:test --date` filtre exactement comme le pipeline (`docs/decisions.md`).

Ajout du 30/09/2026 (branche `feat/export-matches-for-grok`) :
`matches:export-today`, export Markdown des matchs du jour pour une analyse externe,
lecture seule (`docs/decisions.md`).

**À faire par l'utilisateur** : `docs/deploiement.md`, étapes 1 à 8. La bascule (étape
7) se fait en une seule session ; son point de non-retour est la pose de la crontab.

---

## Plus tard, si les mesures le justifient

- Agents IA ou combinés : supprimés le 14/09/2026. Un retour serait une réécriture
  complète, et seulement sur une mesure prouvant leur apport.
