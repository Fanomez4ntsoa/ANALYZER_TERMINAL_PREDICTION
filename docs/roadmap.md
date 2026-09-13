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

Reste à faire :

- Lancer `backtest:run --sample=work` sur les 22 divisions
- Second jeu d'entrées : `--input=ps` (Pinnacle ouverture)
- Décider, sur l'échantillon de travail seulement, du sort de l'avantage domicile
  appliqué après le signal marché, et du calage du total de buts en transfert
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
