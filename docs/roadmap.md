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

## Étape 2 — Backtest honnête 🔄 en cours (branche `feat/backtest-football-data`)

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

Reste à faire (ordre fixé par l'utilisateur ; inversion des deux premiers
points proposée, en attente de décision) :

- Facteur multiplicatif par championnat sur le total de grille, estimé sur les
  saisons antérieures, mesuré sur le modèle à recalage conjoint
- Correction de Dixon-Coles sur les scores faibles, mesurée sur le nul, le BTTS
  (Oui sous-estimé de 2,5 points dans le Top 5), les lignes 1.5 et 3.5 et le
  décalage de l'Over en transfert. Les commentaires du code affirment à tort
  qu'elle existe.
- Second jeu d'entrées : `--input=ps` (Pinnacle ouverture)
- Seulement ensuite : `--sample=holdout`

Questions auxquelles cette étape doit répondre : le Poisson sur cotes est-il
calibré, sur quels marchés, sur quels championnats, et bat-il la clôture ?

---

## Étape 3 — Interface ⬜ à faire

Après l'étape 2, puisque l'interface affiche ce que le modèle produit et que
l'étape 2 peut changer le périmètre.

Maquette de référence validée : thème sombre phosphore, calibration en élément
principal, simulation Monte-Carlo animée, tout en une page sans défilement.
Prévoir un interrupteur d'animation dans l'interface plutôt qu'une obéissance
stricte au réglage système.

---

## Étape 4 — Journal des sélections ⬜ à faire

Une table qui enregistre chaque sélection affichée avec sa probabilité, sa cote
et son horodatage, puis la clôture avec le résultat réel.

C'est ce qui rend le système mesurable en conditions réelles, et la seule voie
pour savoir un jour si les signaux autres que le marché apportent quelque chose.

---

## Plus tard, si les mesures le justifient

- Réactivation ou suppression définitive des agents IA. Ils ne sont pas
  réintroduits sans mesure prouvant leur apport.
- Réactivation des combinés.
- Remplacement de `env()` par `config()` hors config, puis activation de
  `config:cache`.
- Correction du signal `xg_proxy`, qui est aujourd'hui un pourcentage de victoire
  multiplié par 0.03 et n'a pas de sens dimensionnel.
