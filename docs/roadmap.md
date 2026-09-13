# Feuille de route

---

## Étape 1 — Simplification ✅ 13/09/2026

Le système sort des probabilités, plus de verdicts. 12 116 lignes supprimées,
637 ajoutées. Contrôleur de 1635 à 345 lignes.

Inclut le passage à une cote unique de bookmaker jouable.

---

## Étape 1b — Correctifs d'intégrité 🔄 en cours

- Étiqueter `legacy_max` les cotes des matchs importés avant le commit c6f76e2,
  au lieu de les faire passer pour du Bet365
- Arrêter l'écrasement des cotes API-Football par The Odds API
- Colonne `odds_fetched_at` sur `matches`, lue par `predictions.odds_taken_at`
- Sortie propre de `ComboSelectorService` et `GenerateDailyCombosJob`

---

## Étape 2 — Backtest honnête ⬜ à faire

**L'étape décisive.** Rien de structurant ne se décide avant ses résultats.

- Import des CSV football-data.co.uk, cinq saisons pour commencer
- Réécriture du `BacktestEngine` : il doit faire tourner le modèle de production,
  pas une version simplifiée, et aux cotes réelles présentes dans les fichiers
- Mesures : score de Brier, fréquence observée par tranche de probabilité
  annoncée, segmentation par marché et par championnat
- Comparaison à la clôture Pinnacle

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
