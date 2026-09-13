# Décisions

Journal en **ajout uniquement**. On n'efface pas une entrée, on en ajoute une qui
la remplace. Une entrée dit ce qui a été décidé et pourquoi, pas comment c'est
implémenté.

---

## 2026-09-13 — Le système ne décide plus

Suppression de tous les verdicts BET / NO BET / LEAN, des scores de priorité et
des niveaux.

Deux raisons. D'abord, les seuils de décision étaient enfouis dans le pipeline de
calcul, donc impossible de changer une règle sans toucher au modèle ni d'évaluer
le modèle indépendamment des règles. Ensuite, la décision d'engager de l'argent
appartient à l'utilisateur.

Le modèle produit une probabilité, l'interface affiche la cote et l'écart.

---

## 2026-09-13 — Suppression des sources A/B/C et de la Source E

Les sources A, B et C étaient saisies à la main depuis des sites externes. Leurs
fiabilités déclarées reposaient sur 33 matchs, soit une trentaine de picks par
marché : l'intervalle de confiance dépasse 15 points et les écarts entre 0.86,
0.88 et 0.90 étaient du bruit. Le formulaire injectait en plus trois sources
fictives avec des valeurs par défaut quand l'utilisateur ne saisissait rien.

La Source E dérivait des prédictions API-Football, elles-mêmes issues d'un
Poisson sur cotes. La faire voter avec la Source D créait un faux consensus qui
accordait un bonus de score à de la redondance — deux thermomètres branchés sur
le même capteur.

Sans elles, il n'y a plus de consensus à calculer : toute la machinerie
`AnalyzerService` / `CalculatorService` / `reliability.php` disparaît.

---

## 2026-09-13 — Une seule cote, d'un seul bookmaker

Le système stockait la cote maximale parmi 13 à 23 bookmakers. Cette cote
n'existe chez personne : elle sous-estime toutes les probabilités implicites et
surestime tous les écarts, de façon systématique et dans le même sens.

Désormais une seule source, API-Football `/odds`, bookmaker Bet365. Si le
bookmaker est absent de la réponse, aucune cote n'est stockée. The Odds API ne
sert plus qu'aux snapshots du CLV et n'écrit plus dans `matches`.

Les cotes déjà en base restent des maximums et sont étiquetées `legacy_max`.

---

## 2026-09-13 — Deux probabilités issues de la cote, pas une

`1 / cote` inclut la marge du bookmaker : la somme des probabilités implicites
d'un marché dépasse 100 %, ce qui sous-évalue l'écart de 3 à 7 points — plus que
les écarts qu'on cherche à détecter.

D'où deux colonnes. `implied_probability` reste brute : c'est le seuil réel à
franchir en pratique. `fair_probability` retire la marge par normalisation de
l'ensemble de marché complet, et c'est contre elle que l'écart se calcule. Si une
cote de l'ensemble manque, `fair_probability` reste nulle pour tout l'ensemble.

---

## 2026-09-13 — Aucune table supprimée

Supprimer du code est réversible par git. Supprimer des données ne l'est pas.
`recommendations` garde la trace de 235 analyses, `match_validations` les 33
matchs validés à la main — la seule vérité terrain humaine du projet.

Les modèles Eloquent et le code sont supprimés, les tables restent orphelines en
base.

---

## 2026-09-13 — `ContextEnricherService` reste dans le flux

Ce service ne nourrit pas le modèle et pourrait donc être débranché. Il est
conservé parce qu'il collecte fatigue, enjeux et météo **avant** le coup d'envoi,
sans contamination temporelle. C'est précisément ce qui manque aux matchs
importés rétroactivement.

Il construit ainsi, dès maintenant, l'historique de features propre qui
permettra un jour de tester si ces signaux apportent quelque chose. Un champ
`collected_at` horodate chaque collecte.

---

## 2026-09-13 — La calibration remplace le taux de réussite

Le taux de réussite ne veut rien dire sans la cote associée. Un ticket est
rentable au-delà de `1 / cote totale` de réussite : un combiné de 4 matchs à cote
2.00 exige environ 84 % par sélection. Le backtest de l'ancien système célébrait
76,5 % sur Double Chance, un marché coté autour de 1.30, donc rentable à 77 % :
ce « bon » résultat était en réalité perdant.

La métrique de pilotage devient la calibration, mesurée par le score de Brier et
par la fréquence observée par tranche de probabilité annoncée.

---

## 2026-09-13 — Le backtest se fera sur des données externes

Les 960 matchs importés par `pipeline:backfill` ont été enrichis après le coup
d'envoi : classement, compositions, statistiques et prédictions recalculées avec
les données du jour de l'import. Aucune évaluation de features n'est possible
dessus.

Le backtest se fera sur les CSV libres de football-data.co.uk, qui fournissent
résultats et cotes par bookmaker identifié, dont Bet365 et la clôture Pinnacle.

Limite acceptée : ces fichiers ne contiennent que des cotes et des résultats. Le
backtest ne mesurera donc que le signal marché du modèle, pas l'apport des trois
autres signaux. Cette question-là ne sera tranchée qu'en enregistrement réel.

---

## 2026-09-13 — Le backtest mesure la calibration, jamais la rentabilité

Aucune mise, aucun ROI, aucun yield, aucune bankroll. L'ancien moteur calculait
un ROI à des cotes inventées (`100 / probabilité`), ce qui garantit un edge nul
par construction et rend le chiffre indéchiffrable. Une cote qui manque exclut
la ligne et se compte dans les exclusions ; elle ne se remplace jamais.

---

## 2026-09-13 — Le modèle testé est celui de production

Pas de version simplifiée : `XGModelService::predict` reçoit un drapeau
`marketOnly` qui n'active que le signal marché et renormalise les poids, le
reste du pipeline étant inchangé. Ce que le backtest mesure est donc exactement
ce que `/analysis` calcule quand les autres signaux manquent — avantage domicile
compris, alors que le marché le contient déjà. La passe de validation le montre
(+5,9 points sur la victoire à domicile). On mesure d'abord, on règle ensuite,
et seulement sur l'échantillon de travail.

---

## 2026-09-13 — Trois familles de marchés, pas une seule calibration

Le modèle ajuste ses λ sur les cotes 1X2 et O/U 2.5 : sa probabilité sur ces
deux marchés est quasiment celle du bookmaker, et une bonne calibration n'y
prouve rien. D'où trois familles :

- **ajustement** (1X2, O/U 2.5) : contrôle de cohérence de l'inversion des cotes ;
- **dérivés** : BTTS, O/U 1.5 et O/U 3.5 sont les seuls tests indépendants, parce
  qu'ils dépendent de la forme de la loi jointe. La double chance est une somme
  du 1X2 côté modèle comme côté référence : elle n'apporte rien de plus que le
  contrôle 1X2, même si c'est le marché principal du projet ;
- **transfert** : λ ajustés sur le seul 1X2, puis O/U 2.5 prédit et comparé à la
  cote réelle de ce marché. C'est ce qui dit si le Poisson transfère
  l'information d'un marché à l'autre.

---

## 2026-09-13 — Ouverture en entrée, clôture Pinnacle en référence

Le modèle ne reçoit que les cotes d'ouverture, les seules réellement jouables au
moment de la décision. La clôture Pinnacle démarginalisée, meilleur estimateur
disponible de la vraie probabilité, sert de référence et n'entre jamais dans le
modèle. Pinnacle ouverture est importée aussi, pour tester plus tard si le
bookmaker d'entrée change quelque chose.

Limite acceptée : football-data ne publie aucune cote BTTS, double chance,
O/U 1.5 ou 3.5. Les marchés dérivés sont donc calibrés contre le résultat
observé seulement, ce qui reste un test valide avec 38 000 matchs.

---

## 2026-09-13 — Saisons réservées

2021/22 à 2023/24 forment l'échantillon de travail. 2024/25 et 2025/26 sont
réservées : `backtest:run` refuse de les toucher sans `--sample=holdout`, et
tout run sur elles porte un avertissement. Aucun paramètre ne se règle dessus.
