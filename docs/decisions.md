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

---

## 2026-09-13 — Encodage des CSV football-data détecté ligne par ligne

football-data.co.uk ne publie pas un encodage unique : `2122/EC.csv` est en
Windows-1252 (King’s Lynn, octet `\x92`, rejeté par MariaDB) alors que
`2425/D2.csv` et `2526/D2.csv` sont en UTF-8 (Preußen Münster). Une conversion
globale depuis Windows-1252 aurait donné « PreuÃŸen MÃ¼nster », et retirer les
octets invalides aurait donné « Kings Lynn ». `CsvParser` teste donc chaque
ligne : UTF-8 valide conservée telle quelle, sinon convertie depuis
Windows-1252. Aucun caractère n'est supprimé.

L'import se termine par un audit (`TeamNameAudit`) : noms d'équipes distincts
par division, toutes saisons confondues, et signalement de toute paire qui ne
diffère que par un caractère non-ASCII, parce que deux graphies de la même
équipe casseraient toute jointure ultérieure. Premier import complet : aucune
paire, trois noms non-ASCII en tout (King’s Lynn, Preußen Münster).

Le zip d'une saison présent dans `storage/app/private/football-data/{saison}/`
n'est jamais retéléchargé (`--force-download` pour rafraîchir), et des CSV déjà
décompressés dans `{saison}/csv/` ou `{saison}/` suffisent sans zip.

---

## 2026-09-13 — Le backtest se lit par population, jamais sur les 22 divisions confondues

Sur 7 800 matchs par saison, les cinq grands championnats n'en font que 1 750.
Un agrégat sur 22 divisions décrit surtout la quatrième division anglaise. Les
résultats se lisent donc en trois populations aux efficiences de marché
différentes : Top 5 (E0, D1, I1, SP1, F1), deuxièmes divisions (E1, D2, I2, SP2,
F2), inférieures et autres (le reste). Aucun chiffre agrégé toutes divisions
n'est produit ni cité. Le rapport par population se calcule sur
`backtest_fd_predictions` (`storage/app/private/backtest/tools/report-populations.php`,
à transformer en commande `backtest:report` si l'usage se confirme).

Premier run complet (run #2, travail, Bet365 ouverture, 23 388 matchs) : les
deux défauts vus à la validation sont uniformes selon le niveau. Le biais
domicile 1X2 vaut +4,9 / +5,3 / +4,7 points selon la population, et se
décompose en un décalage constant du modèle par rapport à la cote d'entrée
démarginalisée (+4,6 à +5,6 dans chacune des dix divisions majeures) : c'est
l'avantage domicile ajouté après le signal marché, qui le contient déjà. Le
décalage de l'Over 2.5 en transfert vaut −5,3 / −5,9 / −5,5 points, et croît
avec le niveau de buts du championnat (D2 −10,6 à 2,98 buts/match, SP2 −3,8 à
2,23) : le total de buts implicite en transfert ne suit pas le championnat.
Les marchés dérivés indépendants (BTTS, O/U 1.5, O/U 3.5) sont à moins de 3
points de l'observé dans les trois populations.

---

## 2026-09-13 — Avantage domicile : plus jamais appliqué au signal marché

Le facteur domicile était appliqué après la fusion des signaux, donc aussi à la
part de λ issue des cotes, qui pricent déjà l'avantage du terrain. Il s'applique
désormais aux seuls signaux xg_proxy et comparison, avant la fusion ; en mode
marché seul il ne s'applique plus. L'ancien comportement reste disponible pour la
mesure (`backtest:run --legacy-home`).

Prédiction formulée avant le run : le Brier 1X2 rejoint celui de Bet365 ouverture
démarginalisé et reste au-dessus de la clôture Pinnacle. Run #4 (correction seule) :
confirmée dans les trois populations. Écart au Brier d'entrée +0,0003 / +0,0003 /
+0,0001, écart à Pinnacle +0,0008 / +0,0013 / +0,0014, aucune division sous
Pinnacle. Biais domicile ramené de +4,9 / +5,3 / +4,7 à +1,0 / +1,3 / +0,8.

Le résidu ne vient pas du facteur domicile : le modèle annonce 1,6 à 2,1 points de
nul de moins que sa cote d'entrée, répartis sur le domicile et l'extérieur. La
grille 1X2 reproduit la cote à 0,2 point près ; c'est le rééchelonnage du total sur
l'O/U à partage λh/λa constant qui fait baisser le nul. Non corrigé, en attente de
validation d'un ajustement conjoint.

L'hypothèse selon laquelle le biais domicile expliquait le BTTS Oui sous-estimé du
Top 5 est rejetée : l'écart passe de −2,6 à −3,0.

---

## 2026-09-13 — Ancrage du total sur la moyenne du championnat : pas retenu en l'état

Sans cotes O/U, le total est remplacé par la moyenne de buts du championnat
(`LeagueGoalAverages`, saisons de travail). Run #5 (seul) et run #3 (avec la
correction domicile) : le décalage moyen de l'Over 2.5 en transfert disparaît
(+0,3 / −0,7 / +0,1 au run #3), mais le Brier ne suit pas.

Deux défauts, mesurés :

- **Fuite dans la mesure.** Sur l'échantillon de travail, l'ancre est la moyenne
  des matchs mêmes qu'on évalue. Les moyennes varient jusqu'à 0,64 but par match
  d'une saison à l'autre (E3), 0,46 en E0. Mesure refaite hors échantillon sur 2223
  et 2324 (15 582 matchs), ancre calculée sur les seules saisons antérieures, avec
  reproduction exacte des runs #3 et #4 sur l'ancre en échantillon et le total
  libre : l'avantage de Brier de l'ancre se réduit de 0,0011 / 0,0015 / 0,0020, et
  le décalage résiduel remonte à −0,6 / −1,1 / −2,9. Toute ancre mesurée en
  backtest doit n'utiliser que des saisons antérieures au match.
- **Perte de résolution.** Un total constant par championnat efface la variation
  match par match que la grille tirait du 1X2. Corrélation entre P(Over) annoncée
  et résultat, 2223-2324 : 0,196 → 0,091 (Top 5), 0,145 → 0,114 (deuxièmes),
  0,143 → 0,048 (inférieures).

Bilan hors fuite, Brier transfert libre → ancre antérieure : 0,2440 → 0,2468
(Top 5, pire), 0,2480 → 0,2453 (deuxièmes, mieux), 0,2497 → 0,2500 (inférieures,
neutre). Pinnacle clôture : 0,2370 / 0,2406 / 0,2407.

L'ancrage reste actif par défaut dans le code commité (`config/xg-model.php`), en
attendant une décision. Piste proposée, non codée : corriger la moyenne du total de
grille par un facteur multiplicatif par championnat estimé sur les saisons
antérieures, ce qui garde la variation match par match.

---

## 2026-09-13 — Règle du backtest : tout paramètre estimé l'est sur des saisons strictement antérieures

Tout paramètre estimé sur des matchs historiques (moyenne de championnat, facteur
de calibration, constante réglée) ne peut l'être, pour un match évalué, que sur
des saisons strictement antérieures à la sienne. Cela vaut pour l'ancre du total
comme pour tout paramètre futur. L'ancre mesurée aux runs #3 et #5 violait cette
règle : elle incluait les matchs évalués.

C'est une règle du moteur, pas une précaution au cas par cas :

- tout service qui estime un paramètre depuis `historical_matches` implémente
  `SeasonScopedEstimator` et est tagué dans `AppServiceProvider` ;
- `CalibrationBacktestService` borne chaque estimateur tagué à la saison du match
  avant de le prédire, puis retire le bornage en fin de run ;
- pendant un run, un estimateur lu sans bornage lève une exception au lieu de fuir ;
- sans saison antérieure disponible (2122, première saison importée), il n'y a pas
  d'estimation : le paramètre n'est pas appliqué, aucune constante ne le remplace.

Les saisons réservées ne servent jamais d'estimation, même antérieures : un match
de 2526 s'estime sur 2122 à 2324 seulement. En production, sans bornage, les
estimateurs utilisent les saisons de travail.

---

## 2026-09-13 — Ancrage du total désactivé par défaut

Sans fuite, l'ancre absolue dégrade le Brier du transfert dans le Top 5 : un total
constant par championnat efface la variation match par match que la grille tire
des cotes 1X2. On ne garde pas en production une correction qui échange de la
variance utile contre une moyenne juste. L'ancrage reste disponible
(`config/xg-model.php`, `backtest:run --anchor`), désactivé.

---

## 2026-09-13 — Recalage conjoint du total et du partage

Avec cotes Over/Under, le total λh + λa est fixé par P(Under 2.5) démarginalisée,
puis le partage domicile-extérieur est recherché sur le 1X2 à ce total. Avant, le
partage trouvé par la grille à un total libre était conservé lors du recalage,
ce qui retirait 1,6 à 2,1 points au nul (run #4). Même critère d'erreur que la
grille (somme des écarts absolus), un seul paramètre libre : une seule chose
change. L'ancien chemin reste mesurable (`--legacy-share`) et reproduit le run #4
à l'identique (4 800 lignes, écart nul).

Sous deux Poisson indépendantes, le total de buts suit une Poisson de paramètre
λh + λa : P(Under 2.5) ne dépend que du total, et le total se calcule exactement.

Prédictions notées avant le run #6 :

- **Claude** : biais du nul disparu, biais domicile sous 0,5 point.
- **Utilisateur** : Brier 1X2 légèrement sous celui de Bet365 ouverture
  démarginalisé, puisque le modèle utilise deux marchés au lieu d'un, et toujours
  au-dessus de Pinnacle clôture. S'il passe sous Pinnacle : chercher une fuite.

Réserve de Claude, formulée avant le run après une sonde sur cinq profils de cotes :
à total fixé par l'O/U, aucun partage ne rend le nul du marché. Il manque environ
2 points dans tous les cas (−0,7 à −2,4). Le recalage conjoint déplace le résidu
vers l'outsider, il ne le supprime pas. La première prédiction devrait donc
échouer sur le nul. Ce déficit est la signature de l'indépendance des deux lois,
ce qui renvoie à Dixon-Coles.

---

## 2026-09-13 — Run #6 : le recalage conjoint ne résorbe pas le nul, les deux prédictions échouent en partie

Run #6 (recalage conjoint, domicile corrigé, ancrage désactivé, Bet365 ouverture,
23 388 matchs), comparé au run #4 (même modèle, partage constant) :

| Population | Biais domicile #4 → #6 | Nul, écart à l'observé #4 → #6 | Extérieur #4 → #6 | Brier 1X2 #4 → #6 | Entrée | Pinnacle |
|---|---|---|---|---|---|---|
| Top 5 | +1,0 → +0,6 | −2,4 → −2,2 | +1,4 → +1,6 | 0,1926 → 0,1929 | 0,1924 | 0,1919 |
| Deuxièmes divisions | +1,3 → +0,5 | −2,3 → −2,1 | +1,0 → +1,6 | 0,2092 → 0,2093 | 0,2089 | 0,2079 |
| Inférieures et autres | +0,8 → +0,2 | −1,4 → −1,2 | +0,7 → +1,0 | 0,1963 → 0,1965 | 0,1963 | 0,1950 |

- **Prédiction de Claude** : échec sur le nul, comme annoncé par la sonde avant le
  run. L'écart modèle − cote d'entrée sur le nul passe de −1,6 / −2,1 / −1,7 à
  −1,4 / −1,8 / −1,5. Le biais domicile tombe sous 0,5 dans les divisions
  inférieures seulement (+0,6 / +0,5 / +0,2). Le résidu est passé du domicile à
  l'extérieur.
- **Prédiction de l'utilisateur** : échec sur le premier point. Le Brier 1X2 reste
  au-dessus de Bet365 ouverture (+0,0005 / +0,0004 / +0,0002), un peu plus qu'au
  run #4. Il reste au-dessus de Pinnacle (+0,0010 / +0,0014 / +0,0015) ; aucune
  division ne passe dessous, pas de fuite à chercher.
- L'O/U 2.5 d'ajustement reproduit désormais exactement la cote d'entrée (Brier
  égal à 0,0000 près), et le transfert est identique au run #4, comme attendu.
- BTTS Oui : −3,0 / −1,6 / −1,2 → −2,5 / −1,1 / −0,7. Double chance 1X, marché
  principal du projet, sous-estimé de 1,6 / 1,6 / 1,0 point.

Lecture : le modèle n'exploite pas deux marchés, il les met en contradiction. Deux
Poisson indépendantes ne peuvent pas produire à la fois le total du marché O/U et
la probabilité de nul du marché 1X2. Forcer le total injecte cette erreur de forme
dans le 1X2. C'est la même contradiction, vue de l'autre côté, que le décalage de
l'Over en transfert : sans O/U, la grille atteint le nul du marché en baissant le
total (0,2 à 0,4 but sous le total O/U sur les profils sondés), d'où −6,7 points
sur l'Over.

Recalage conjoint conservé par défaut : il est géométriquement juste, reproduit
l'O/U à l'identique et réduit le biais domicile ; sa perte de Brier 1X2 (0,0001 à
0,0003) est inférieure au gain de la correction domicile. Proposition soumise à
décision : passer Dixon-Coles avant le facteur multiplicatif, puisque le résidu
sur le nul, qui contamine les autres mesures, est toujours là.

---

## 2026-09-13 — Dixon-Coles : la cause est l'indépendance, pas le partage ni le total

Le run #6 l'a montré : deux lois de Poisson indépendantes ne peuvent pas produire à
la fois le total du marché O/U et le taux de nul du 1X2. On corrigeait des
symptômes. La correction de Dixon & Coles (1997) s'attaque à la cause : un facteur
τ sur les scores 0-0, 1-0, 0-1 et 1-1, puis renormalisation de la matrice.

**Estimation de ρ.** Maximum de vraisemblance sur les scores observés, jamais sur
les cotes (`DixonColesRho` ne lit que saison, division, équipes et buts). Modèle
complet de Dixon-Coles : attaque et défense par équipe, avantage domicile par
championnat-saison, ρ par population (Top 5, deuxièmes divisions, autres).
Maximisation alternée ; l'estimateur retrouve ρ = −0,12, 0 et −0,20 sur des scores
simulés, chaque fois dans son intervalle de confiance.

**Règle des saisons antérieures, appliquée par le moteur.** Pour un match de la
saison s, ρ est estimé sur les saisons de travail strictement antérieures à s. Trois
garde-fous lèvent une exception : estimateur lu sans bornage pendant un run ; ligne
d'une saison non antérieure dans les données d'estimation, quel que soit le
chargeur ; estimateur utilisé par le modèle mais non borné par le moteur. Sans
saison antérieure (2122) : pas de ρ, deux Poisson indépendantes, donc 2122 est
identique au run #6. La comparaison qui compte porte sur 2223 et 2324.

**Anciens comportements reproductibles.** `--legacy-poisson` reproduit le run #6 et
`--legacy-poisson --legacy-share` le run #4, à l'identique (4 800 lignes chacun,
écart nul).

**ρ estimés avant le run (scores seulement) :**

| Population | Saison évaluée 2223 (estimé sur 2122) | Saison évaluée 2324 (estimé sur 2122-2223) |
|---|---|---|
| Top 5 | −0,069, IC 95 % [−0,131 ; −0,008] | −0,042, IC 95 % [−0,086 ; +0,002] |
| Deuxièmes divisions | −0,091, IC 95 % [−0,150 ; −0,032] | −0,057, IC 95 % [−0,098 ; −0,016] |
| Inférieures et autres | −0,059, IC 95 % [−0,103 ; −0,015] | −0,044, IC 95 % [−0,075 ; −0,013] |

Les intervalles se recouvrent largement : les trois populations ne se distinguent
pas. Un ρ unique serait une simplification défendable.

**Faits mathématiques, établis avant le run.** Les quatre corrections s'annulent en
somme et portent sur des scores d'au plus deux buts : à λ fixés, ρ ne change ni
P(Under 2.5) ni P(Under 3.5). Il augmente le nul, le BTTS Oui et l'Over 1.5 quand
ρ < 0. L'O/U 3.5 ne peut bouger que par le déplacement des λ.

**Repère, sans servir à l'estimation.** Une sonde sur cinq profils de cotes montre
que 1X2 et O/U deviennent cohérents vers ρ = −0,10 : l'écart de nul du recalage
conjoint et l'écart entre total de grille et total O/U s'annulent ensemble. Les ρ
estimés sur les scores valent environ la moitié.

Prédictions notées avant le run #7 :

- **Claude** : (1) l'écart sur le nul au 1X2 se réduit nettement ; (2) le décalage
  de l'Over en transfert diminue sans ancre ; (3) le BTTS Oui du Top 5 se
  rapproche de l'observé. Précision ajoutée d'après la sonde et les ρ estimés :
  réduction de l'ordre de la moitié, pas de disparition.
- **Utilisateur** : (4) Brier 1X2 sous Bet365 ouverture démarginalisé ; (5) au-dessus
  de Pinnacle clôture. S'il passe dessous : chercher une fuite, en commençant par
  la période d'estimation de ρ.
- **Hypothèse à trancher** : si ρ résorbe le déficit de nul, le décalage de l'Over en
  transfert diminue sans ancre, et le facteur multiplicatif par championnat devient
  inutile.

---

## 2026-09-13 — Run #7 : Dixon-Coles referme le nul et le transfert ; le facteur multiplicatif est abandonné

Run #7 (Dixon-Coles, recalage conjoint, domicile corrigé, Bet365 ouverture,
23 388 matchs). Contrôle : sur 2122, sans ρ, tous les écarts au run #6 sont nuls.
Lecture sur 2223-2324, comparée au run #6 sur les mêmes saisons :

| Population | Nul − entrée #6 → #7 | Over transfert − observé #6 → #7 | Brier transfert #6 → #7 | Pinnacle | BTTS Oui − observé #6 → #7 | 1X − observé #6 → #7 |
|---|---|---|---|---|---|---|
| Top 5 | −1,4 → −0,3 | −6,9 → −1,7 | 0,2440 → 0,2397 | 0,2370 | −2,5 → −2,0 | −1,9 → −1,2 |
| Deuxièmes divisions | −1,8 → −0,1 | −7,0 → −0,2 | 0,2480 → 0,2439 | 0,2406 | −0,6 → +0,2 | −1,8 → −0,6 |
| Inférieures et autres | −1,5 → −0,4 | −7,3 → −2,4 | 0,2497 → 0,2450 | 0,2407 | −0,7 → −0,2 | −1,2 → −0,4 |

Brier 1X2, écart apparié match par match (erreur type entre parenthèses) :

| Population | Modèle − Bet365 ouv., run #6 | Modèle − Bet365 ouv., run #7 | Modèle − Pinnacle, run #7 |
|---|---|---|---|
| Top 5 | +0,00043 (0,00013) | +0,00012 (0,00007) | +0,00073 (0,00027) |
| Deuxièmes divisions | +0,00035 (0,00015) | +0,00002 (0,00008) | +0,00131 (0,00029) |
| Inférieures et autres | +0,00022 (0,00010) | +0,00001 (0,00006) | +0,00104 (0,00022) |

Prédictions :

- (1) nul nettement réduit : **confirmée**, 73 à 94 % du déficit résorbé, plus que la
  moitié annoncée par Claude.
- (2) décalage de l'Over en transfert en baisse sans ancre : **confirmée**, de −7
  points à −1,7 / −0,2 / −2,4. L'écart de Brier du transfert à Pinnacle est divisé
  par deux (+0,0073 / +0,0074 / +0,0091 → +0,0029 / +0,0034 / +0,0043).
- (3) BTTS Oui du Top 5 plus proche : **marginale**, −2,5 → −2,0. Dixon-Coles
  n'explique pas l'essentiel de cet écart (E0 −2,2, D1 −2,9, F1 −3,2).
- (4) Brier 1X2 sous Bet365 ouverture : **échec**. Le modèle rejoint sa cote d'entrée,
  indiscernable du bruit, sans passer dessous. Des marchés cohérents entre eux
  portent la même information : l'O/U n'ajoute rien au 1X2.
- (5) au-dessus de Pinnacle clôture : **confirmée** dans les trois populations.
  Aucune fuite à chercher.
- O/U 3.5 inchangé, comme établi avant le run. L'Over 1.5 des deuxièmes divisions
  passe à +1,9 (surestimé).

**Facteur multiplicatif par championnat : abandonné**, selon la règle fixée avant le
run : le déficit de nul est résorbé et le décalage du transfert a baissé sans ancre.
Point de vigilance, sans rien construire dessus : le résidu reste de −3 à −5 points
dans des divisions à buts (E0, D1, D2, N1, G1, SC0, SC1), chacune à moins de deux
erreurs types, et la population au ρ le plus fort (deuxièmes divisions) a le plus
petit résidu.

**ρ par population** : les intervalles de confiance se recouvrent pour les deux
saisons évaluées. Les populations ne se distinguent pas statistiquement.

---

## 2026-09-13 — Observation non exploitée : résidu de l'Over dans les divisions à buts

Après Dixon-Coles (run #7), le décalage de l'Over 2.5 en transfert reste de −3 à −5
points dans sept divisions à buts (E0, D1, D2, N1, G1, SC0, SC1). **On ne construit
rien dessus.** Chaque écart reste sous deux erreurs types, et ces sept divisions ont
été sélectionnées parmi vingt-deux précisément pour leur écart : c'est le piège du
meilleur segment, où la sélection elle-même fabrique l'effet. L'observation est
consignée pour être revue sur l'échantillon réservé, pas pour justifier un réglage.

---

## 2026-09-13 — ρ unique sur toutes les divisions

Les intervalles de confiance des trois ρ par population se recouvrent largement
(run #7) : un paramètre par population n'est pas justifié par les données. Un
paramètre de moins, c'est une occasion de moins de se tromper. ρ est désormais
estimé une fois sur toutes les divisions, saisons strictement antérieures
(`dixon_coles_rho_scope` = `global`). `--rho-per-population` reproduit le run #7 à
l'identique.

ρ unique estimé avant le run, scores seulement :

| Saison évaluée | Saisons d'estimation | Matchs | ρ | IC 95 % | ρ par population au run #7 |
|---|---|---|---|---|---|
| 2223 | 2122 | 7 822 | −0,070 | [−0,101 ; −0,040] | Top 5 −0,069, deuxièmes −0,091, autres −0,059 |
| 2324 | 2122-2223 | 15 652 | −0,047 | [−0,069 ; −0,026] | Top 5 −0,042, deuxièmes −0,057, autres −0,044 |

Prédictions notées avant le run #8 (Bet365 ouverture, ρ unique), comparé au run #7
sur les matchs communs de 2223-2324 :

- **Claude** : aucune population ne se dégrade de plus de deux erreurs types sur le
  1X2, le transfert, le BTTS ou les O/U 1.5 et 3.5. Top 5 quasi inchangé (ρ presque
  identique). Deuxièmes divisions : ρ plus faible en valeur absolue, donc nul et
  Over en transfert un peu plus sous-estimés (Over transfert de −0,2 vers −1
  environ). Divisions inférieures : ρ un peu plus fort en 2223, léger mieux.
- **Utilisateur** : la mesure ne se dégrade dans aucune population.

---

## 2026-09-13 — Sensibilité à la source : Pinnacle ouverture contre Bet365 ouverture

Run #9 : même modèle que le run #8 (Dixon-Coles, ρ unique), entrée Pinnacle
ouverture (`--input=ps`). C'est une mesure de sensibilité à la source, pas une
recherche d'avantage. Comparaison appariée sur les matchs communs aux deux runs.

Couverture Pinnacle ouverture équivalente à Bet365 sur l'échantillon de travail ;
marge 1X2 moyenne 2,5 à 4,5 % contre 5,4 à 7,1 % pour Bet365.

Prédictions notées avant le run #9 :

- **Claude** : le modèle suivant sa cote d'entrée à une erreur type près (run #7),
  le Brier 1X2 nourri à Pinnacle ouverture est plus bas que nourri à Bet365
  ouverture dans les trois populations, d'un écart du même ordre que celui entre
  les deux cotes d'entrée démarginalisées. Le gain devrait être le plus visible
  hors Top 5, où les marges Bet365 sont les plus fortes. Le transfert et les
  marchés dérivés suivent dans le même sens. Le modèle reste au-dessus de Pinnacle
  clôture.
- **Point de vigilance, écrit avant le run** : Pinnacle ouverture et Pinnacle clôture
  viennent du même bookmaker. Si le modèle nourri à Pinnacle ouverture rejoint ou
  passe sous Pinnacle clôture, vérifier d'abord ce que contiennent réellement les
  colonnes PSH/PSD/PSA de football-data (heure de relevé) avant toute conclusion.

---

## 2026-09-13 — Runs #8 et #9 : ρ unique retenu, Pinnacle ouverture légèrement meilleure hors Top 5

Lecture sur 2223-2324, comparaisons appariées sur les matchs communs (Δ Brier,
erreur type, z). Les tableaux par population des deux runs ne se comparent pas
directement : les exclusions diffèrent (42 matchs du Top 5 sans O/U Pinnacle).

**Run #8, ρ unique contre ρ par population (run #7), Bet365 ouverture :**

| Population | 1X2 | Transfert O/U 2.5 | BTTS | O/U 1.5 | O/U 3.5 |
|---|---|---|---|---|---|
| Top 5 | −0,00002 (z −3,5) | −0,00011 (z −0,8) | −0,00001 (z −1,2) | −0,00001 (z −1,8) | 0,00000 (z −0,8) |
| Deuxièmes divisions | +0,00004 (z +1,4) | +0,00001 (z 0,0) | +0,00001 (z +0,5) | −0,00008 (z −2,8) | 0,00000 (z +0,4) |
| Inférieures et autres | −0,00001 (z −1,4) | +0,00004 (z +0,3) | +0,00001 (z +0,8) | +0,00001 (z +0,6) | 0,00000 (z −0,9) |

- Prédiction de l'utilisateur, aucune dégradation : **confirmée**, aucun Brier ne se
  dégrade de plus de deux erreurs types. **ρ unique retenu.**
- Prédiction de Claude : **confirmée dans le sens**. Top 5 quasi inchangé. Divisions
  inférieures : Over en transfert −2,4 → −1,7. Deuxièmes divisions : nul −0,4 → −0,8
  et Over en transfert −0,2 → −1,7, un peu plus que le −1 annoncé, à Brier
  identique. Les trois populations ont désormais un décalage de transfert voisin
  (−1,5 / −1,7 / −1,7).

**Run #9, Pinnacle ouverture contre Bet365 ouverture (run #8), même modèle :**

| Population | Δ Brier 1X2 modèle | Δ Brier 1X2 des cotes d'entrée | Δ transfert O/U 2.5 | Δ O/U 2.5 ajustement | Modèle Pinnacle − Pinnacle clôture, 1X2 |
|---|---|---|---|---|---|
| Top 5 | −0,00005 (z −0,8) | −0,00009 | −0,00085 (z −1,4) | +0,00011 (z +0,8) | +0,00064 (z +2,4) |
| Deuxièmes divisions | −0,00014 (z −2,0) | −0,00026 | −0,00004 (z −0,1) | −0,00003 (z −0,2) | +0,00121 (z +4,3) |
| Inférieures et autres | −0,00013 (z −1,8) | −0,00019 | −0,00242 (z −4,0) | −0,00018 (z −1,6) | +0,00092 (z +4,4) |

Sur les trois saisons, 2122 comprise, le 1X2 donne −0,00004 / −0,00009 / −0,00019
(z −0,8 / −1,3 / −3,2).

- Prédiction de Claude : **en partie confirmée.** Le Brier 1X2 baisse dans les trois
  populations, mais seulement de la moitié de l'écart entre les deux cotes
  d'entrée, et sans effet détectable dans le Top 5. Le gain est le plus net hors
  Top 5, surtout sur le transfert des divisions inférieures. Les marchés dérivés
  ne suivent pas tous : dans le Top 5, l'O/U 2.5 d'ajustement et les dérivés sont
  un peu moins bons avec Pinnacle, sans être significatifs.
- Le modèle nourri à Pinnacle ouverture reste au-dessus de Pinnacle clôture dans
  les trois populations : **point de vigilance non déclenché**.
- Lecture : la sensibilité à la source est faible. La supériorité de Pinnacle
  ouverture sur Bet365 ouverture ne passe qu'à moitié dans le modèle, et n'est
  mesurable que hors Top 5. Ce n'est pas un avantage : la règle 6 de CLAUDE.md
  impose une cote de bookmaker unique et jouable, et le choix de la source de
  production reste un choix de jouabilité, pas de calibration.

---

## 2026-09-14 — Agents IA, combinés et ancien backtest : supprimés, plus débranchés

Les 5 agents IA, `ClaudeClient`, `ai:batch`, `ai:test`, `ComboSelectorService`,
`ComboBuilderService` et `GenerateDailyCombosJob` étaient débranchés depuis
l'étape 1. Leur code lit des champs supprimés à cette étape (scores, niveaux,
verdicts, sources) : pour revenir, il faudrait le réécrire entièrement, pas le
rebrancher. Le garder débranché n'apportait qu'une fausse impression de
réversibilité. `results:collect` part avec eux : il ne faisait que compter les
décisions BET / LEAN des agents.

L'ancien `BacktestEngine`, son contrôleur et la page `/backtest` calculaient un
taux de réussite et un ROI à des cotes inventées, remplacés par le backtest
football-data. Le dashboard n'affiche plus leurs chiffres.

Le tag `etape-2-terminee` conserve tout ce code. Les tables restent (`ai_analysis`,
`daily_combos`, `combos`, `backtest_runs`, `backtest_predictions`).

---

## 2026-09-14 — Signal `xg_proxy` et dimension arbitre retirés

`xg_proxy` était un pourcentage de victoire API-Football multiplié par 0,03 : un
nombre sans unité cohérente présenté comme des buts attendus. La colonne
`footystats_data` et ses données restent, plus rien ne l'écrit.

La dimension arbitre de `ContextEnricherService` créait à la volée des arbitres
aux statistiques par défaut (4 jaunes, 0,25 penalty par match) : la table
`referees` n'a jamais contenu d'information réelle.

Le retrait de `xg_proxy` change les poids de la fusion en mode complet. Sans
objet : la production passe en mode marché seul à l'étape 3, seule configuration
mesurée par le backtest.

---

## 2026-09-14 — Matchs contaminés marqués définitivement

Colonne `matches.post_kickoff_data` : une donnée du match a été écrite après son
coup d'envoi. Ces matchs sont exclus de toute mesure (scope `measurable()`).

Critère retenu par l'utilisateur, le plus large des trois mesurés : match créé
après son coup d'envoi **ou** `advanced_data` réécrites après. Sur 1 140 matchs :

| Critère | Matchs |
|---|---|
| Importés par le backfill du 08/04/2026 | 794 |
| Créés après le coup d'envoi (791 du backfill, 25 d'autres jours) | 816 |
| Créés ou données avancées réécrites après le coup d'envoi | **913** |

Le chiffre de 960 cité jusqu'ici pour le backfill était faux : 794, dont 3 importés
avant leur coup d'envoi et jamais réécrits, donc non marqués. Les 97 matchs
supplémentaires ont été importés avant le coup d'envoi, puis réécrits par des
relances du pipeline les 26/04 et 01/05/2026 : c'était le fonctionnement normal
du pipeline, pas un accident du backfill.

Cause corrigée : le pipeline n'écrit plus rien sur un match commencé, hormis son
score. Le flag n'est donc plus posé qu'à la création d'un match dont le coup
d'envoi est passé, et n'est jamais retiré.

---

## 2026-09-14 — Toute exception avalée par le pipeline est journalisée

`FormationProfiles`, supprimée à l'étape 1, était encore appelée dès qu'une
composition était publiée. L'exception était avalée par le try/catch de
`FetchMatchDataJob`, qui couvrait aussi la récupération des cotes : pour ces
matchs, les cotes n'étaient jamais relevées, sans rien de visible. Aucun autre
appel à l'une des 41 classes supprimées dans l'historique.

Règle : un try/catch du pipeline qui continue après une exception l'écrit en
avertissement dans le canal `pipeline` (classe, message, emplacement), et chaque
sous-étape a son propre try/catch pour qu'un échec n'en masque pas un autre. Un
échec silencieux qui dure une semaine ne doit plus être possible.

---

## 2026-09-14 — Pipeline automatique et clôture relevée par le système

Planificateur réactivé : `pipeline:daily` à 10:00 UTC (import, contexte,
probabilités, snapshot), avant le créneau 12h-21h UTC pour qu'aucun match du jour
n'ait commencé au moment du calcul. Chaque étape est journalisée ; si l'import
échoue, les suivantes ne tournent pas.

La clôture n'est plus une commande manuelle. Toutes les 5 minutes, un snapshot
sans cache est pris pour les matchs qui commencent dans les 10 minutes ; la cote
de clôture est le dernier snapshot de cette fenêtre, sinon elle reste vide.
L'ancien marquage prenait, faute de snapshot, les cotes API-Football du match :
une autre source, relevée des heures plus tôt. Et le cache de 2 h rendait tout
snapshot potentiellement périmé.

Quota The Odds API : clôturer tous les championnats suivis coûterait environ 600
crédits par mois (≈300 créneaux championnat × heure, 2 crédits l'appel, estimé
sur avril-mai 2026) pour un quota de 500. Clôture limitée au Top 5 (≈260 crédits),
configurable, avec arrêt sous une réserve de 50 crédits. Choix de l'utilisateur.

---

## 2026-09-14 — Une erreur d'API est un échec, jamais une absence de donnée

Premier passage réel : 9 matchs sur 11 sans cote. Bet365 était présent sur les
onze. Cause : la limite de l'offre gratuite API-Football (10 requêtes/minute). Le
code renvoyait `null` sur un HTTP 429, et le pipeline l'a compté comme « sans
cotes », avec 0 échec. La règle du jour même sur les échecs silencieux était
violée par le code qui venait de l'introduire : elle ne couvrait que les
exceptions.

Toute erreur API-Football lève désormais une exception typée (débit, quota
journalier, refus de l'offre, HTTP, réseau). « Sans cotes » ne veut plus dire
qu'une chose : toutes les pages ont été lues et le bookmaker ne cote pas le match.
Des cotes manquantes pour cause d'échec ou de budget rendent le passage incomplet
et son code de sortie non nul.

---

## 2026-09-14 — Les cotes d'abord et seules ; le facultatif cède toujours

La production tourne en marché seul : seules les cotes entrent dans un calcul. Les
prédictions API-Football et les blessures sont collectées pour un test futur.

Ordre imposé : cotes, puis scores de la veille, puis facultatif tant que le budget
du jour reste au-dessus d'une réserve. Une donnée facultative n'empêche jamais une
donnée indispensable ; si le budget se tend, on abandonne le facultatif, jamais
les cotes.

Cotes par `/odds?date=` avec lecture de toutes les pages. Si le budget ne permet
pas de toutes les lire, avertissement explicite avant de continuer, puis liste des
matchs non couverts : jamais de collecte à moitié en silence.

Retirés du passage quotidien : `headtohead` (paramètre `last`), `teams/statistics`
et `standings` pour la saison en cours, refusés par l'offre gratuite ; le
rechargement par `/fixtures?id=`, redondant ; les compositions, jamais publiées à
l'heure du passage.

---

## 2026-09-14 — Le CLV se mesure contre Pinnacle

**Le CLV mesure le mouvement de la cote Pinnacle entre la prédiction et la clôture.
Il ne mesure pas le mouvement du prix Bet365 utilisé pour les prédictions.** Ce sont
deux bookmakers distincts, chacun unique et identifié : Bet365 via API-Football pour
les prédictions (`predictions.bookmaker`), Pinnacle via The Odds API pour le CLV
(`odds_movements.bookmaker`). Cette distinction ne doit jamais se perdre dans une
lecture des chiffres.

Constat : Bet365 n'existe pas sur The Odds API (absent des 95 événements de la région
`eu`, et des régions `uk` et `us`). Avec Bet365 comme référence, aucun snapshot
n'était possible.

Ce n'est pas un compromis. Le CLV sert à savoir si on avait raison contre le marché,
et Pinnacle est l'étalon du marché : c'est contre sa clôture que le modèle a été
mesuré pendant tout le backtest. Décision de l'utilisateur.

Cote de prédiction et cote de clôture viennent toujours du même bookmaker, sinon le
match n'est pas clôturé. Limite constatée : Pinnacle ne publie en `totals` que sa
ligne principale, 2.5 sur 18 événements sur 81 ; le CLV Over 2.5 ne portera que sur
une minorité de matchs, le CLV 1X2 sur tous.

`predictions.bookmaker` vient maintenant de la cote réellement relevée
(`matches.odds_bookmaker`), et non plus d'une config partagée qui aurait étiqueté
des cotes Bet365 comme Pinnacle.

---

## 2026-09-14 — Le contexte dit quand il ne sait pas

Une dimension sans donnée suffisante se déclare indisponible, avec sa raison. Un
zéro ou une valeur par défaut présentés comme une mesure sont plus graves qu'une
absence : ils s'accumulent comme des données et fausseraient tout test futur.

- Fatigue : indisponible tant que la base ne couvre pas le calendrier (filtre
  horaire à l'import, ou semaine sans match importé du championnat). Elle se
  déclarait disponible avec 0/0.
- Enjeux et pression entraîneur : **bloqués par l'offre gratuite**, qui refuse
  `standings` et `teams/statistics` pour la saison en cours. Ce n'est pas un bug,
  on ne cherche pas à les faire marcher.
- Météo : plus de 20 °C, 50 % d'humidité ou « Clear » par défaut ; prévision rejetée
  si elle tombe à plus de 3 h du match.
- Importance : null sans enjeux ni pression. Elle valait « medium » par défaut, et
  « high » dès que le conseil API-Football contenait le mot « draw ».

---

## 2026-09-14 — Cotes par date abandonnées : l'offre gratuite plafonne `page` à 3

La décision du jour même de passer à `/odds?date=` avec lecture de toutes les pages est
remplacée. Relance du 14/09/2026 : 13 pages ce jour-là, refus `plan` dès la page 4
(« Free plans are limited to a maximum value of 3 for the Page parameter »). Limite
non documentée par l'API. Aucun des 11 matchs suivis ne figurait dans les 30 premiers :
0 cote. Le garde-fou a signalé le passage incomplet ; rien n'a été silencieux.

Les cotes se relèvent match par match (`/odds?fixture=`, une page, 1 requête). Choix de
l'utilisateur. Deux ajustements :

- Quand les cotes sont incomplètes, le facultatif n'est pas collecté : le budget reste
  disponible pour une relance. Au premier passage, 22 requêtes de facultatif avaient été
  dépensées alors qu'aucune cote n'était relevée.
- Le budget est estimé au plus pessimiste de trois compteurs, ceux de l'API étant en
  retard (18 et 25 affichés pour 34 requêtes réelles).

Relance vérifiée : 11 matchs sur 11 cotés Bet365, 110 lignes de prédictions étiquetées
`bet365`, 34 requêtes consommées. Projection et options de périmètre dans
`docs/architecture.md`, section « Limites de l'offre gratuite ».

---

## 2026-09-14 — Cotes relevées sur le Top 5 ; matchs et scores sur les 21 ligues

Le relevé des cotes, et avec lui les données facultatives, est restreint au Top 5 :
Premier League (E0, id 39), Bundesliga (D1, 78), Serie A (I1, 135), La Liga (SP1, 140),
Ligue 1 (F1, 61). Configurable par `API_FOOTBALL_ODDS_LEAGUES`. Décision de
l'utilisateur.

L'import des matchs et des scores reste sur les 21 ligues suivies : il coûte une
requête par jour quelle que soit leur nombre, et l'historique des résultats continue
de s'accumuler gratuitement sur tout le périmètre. Le journal indique chaque jour
combien de matchs à venir sont hors périmètre des cotes, par championnat.

Calcul qui la justifie (offre gratuite : 100 requêtes par jour, coût mesuré le
14/09/2026 à environ 3 requêtes par match coté plus 2 fixes) :

| Journée | Matchs cotés | Cotes + matchs + scores | Avec facultatif | Marge sur 100 |
|---|---|---|---|---|
| 21 ligues, samedi 02/05/2026 observé | 66 | 68 | 68 + 22 (11 matchs, réserve atteinte) | ~0 à 10, aucune relance possible |
| Top 5, jour le plus chargé observé | 21 | 23 | 65 | ~35, une relance des cotes (21) possible |

Avec 21 ligues, un samedi consomme le quota à lui seul et un passage raté ne peut pas
être relancé. Avec le Top 5, les cotes coûtent 23 requêtes au lieu de 68, le facultatif
est complet, et la marge couvre une relance.

C'est aussi le périmètre sur lequel l'utilisateur parie, et la population « Top 5 »
dont le backtest a mesuré la calibration.

**Élargir redevient possible avec une offre API-Football supérieure** : il suffit
d'ajouter des ids à `API_FOOTBALL_ODDS_LEAGUES`, à raison d'environ 3 requêtes par
match supplémentaire.

---

## 2026-09-14 — CLV Over 2.5 partiel, accepté en l'état

Pinnacle ne publie en `totals` que sa ligne principale, qui n'était 2.5 que sur 18
événements sur 81 (environ un quart). Le CLV Over/Under 2.5 ne portera que sur ces
matchs. On ne cherche pas à le contourner (lignes alternatives par événement, plus
coûteuses en crédits) : le CLV 1X2 est complet, c'est suffisant pour commencer.

---

## 2026-09-14 — La production calcule en mode marché seul

`model_probability` vient désormais de `XGModelService::predict(marketOnly: true)`, la
configuration du backtest de l'étape 2 (cotes 1X2 et O/U 2.5, avantage domicile hors
marché, recalage conjoint, Dixon-Coles à ρ unique). Décision de l'utilisateur, prise
avant tout affichage.

Raison : c'est la seule configuration mesurée. Afficher des probabilités issues de la
fusion de trois signaux (marché, comparaison API-Football, blessures) à côté d'une
calibration mesurée sur le seul signal marché ferait croire que la courbe décrit ce qui
est affiché. C'est exactement le défaut de l'ancien système.

La fusion à trois signaux n'est pas abandonnée : elle est calculée à chaque prédiction
et stockée à part (`full_model_probability`, `full_model_signals`), sans jamais entrer
dans `model_probability`. Quand le journal des sélections aura tourné quelques mois, les
deux configurations pourront être comparées sur des matchs réels.

Chaque ligne enregistre aussi `model_mode`, λ domicile, λ extérieur et ρ du calcul
affiché : l'animation Monte-Carlo lit ces paramètres, jamais un recalcul.

Écart constaté au passage, Inter-Udinese du 14/09/2026 : 76,1 % sur la victoire à
domicile en marché seul, 74,8 % en fusion complète. Les prédictions déjà en base sont
recalculées après la migration.

---

## 2026-09-14 — Système de design, deuxième revue

Quatre corrections de l'utilisateur sur la page de démonstration :

- **Vidéo inverse unique.** Quatre blocs visibles écrasaient la page ; un
  avertissement permanent n'avertit plus. Un seul bloc par page, l'anomalie la plus
  grave ; les autres états en ligne, vert sombre, bordure d'emphase. Dans le socle,
  seul un état **critique** passe en vidéo inverse : l'écart de configuration du run
  de référence est permanent et ne doit pas occuper ce bloc tous les jours.
- **Vert vif rationné.** Toute la colonne Modèle était en vert vif : cinquante lignes
  vivantes n'ont plus de hiérarchie. Valeurs des tableaux en vert moyen, vert vif sur
  la ligne survolée ou cochée et les valeurs uniques des panneaux.
- **Variante B** (en-têtes 10 px, vert sombre) retenue, comparaison supprimée.
- **Compteur de lueurs mesuré.** L'ancien comptait les `[data-glow]` et affichait 2/2
  pour 3 effets rendus : un `data-glow` sans effet (`text-shadow` sur le conteneur
  d'un canvas), le `shadowBlur` du canvas non vu, un halo radial d'ambiance non vu.
  Le halo est supprimé. L'impression de barres lumineuses dans l'histogramme vient des
  lignes de balayage sur un aplat : aucune lueur mesurable sur ces barres.

Et un principe ajouté : **un écart mécaniquement nul ne doit jamais être présenté
comme une information.** En marché seul, 1X2, DC et O/U 2.5 sont des marchés
d'ajustement ; BTTS (et O/U 1.5, O/U 3.5 s'ils sont affichés) sont dérivés. C'est la
distinction du backtest entre contrôle de cohérence et test indépendant.

Constat à cette occasion : l'écart du 1X2 n'est pas nul. À total fixé par l'O/U 2.5,
un seul partage des λ ne reproduit pas les trois issues ; sur les 33 lignes 1X2 du
14/09/2026, résidu moyen 0,58 pt, maximum 2,74 pts (DC identique), contre 0,03 pt en
moyenne sur l'O/U 2.5. Ce résidu est un défaut d'ajustement, pas une information : il
s'affiche sans couleur, comme le zéro.

## 2026-09-14 — Socle de l'interface

- **Tailwind du terminal séparé** (`tailwind.terminal.config.js`, `@config`) : palette,
  tailles et espacements remplacés par les jetons, ombres et flous désactivés. Une
  couleur hors système ne génère rien. Breeze et `layouts.pro` gardent leur config
  jusqu'à leur migration, pour ne rien casser en cours de route.
- **Polices copiées dans `resources/fonts`** plutôt qu'une dépendance npm : onze woff2
  (184 Ko, licence OFL jointe), sous-ensembles latin, latin-ext et grec pour λ et ρ.
  Le sous-ensemble latin couvre U+2212.
- **Composants CSS hors `@layer`** : Tailwind purge les classes de couche absentes des
  vues scannées ; une classe du système ne doit pas disparaître parce qu'aucune page
  ne l'utilise encore.
- **Règles d'affichage en PHP testé**, pas dans les vues : `MarketNature` (couleur de
  l'écart, exception pour un marché non classé), `Fmt` (jamais « −0,0 », valeur
  absente = vide), `SystemState::arrange`, `PipelineFreshness::evaluate`
  (délai de grâce 30 min après 10:00 UTC, passage en cours jugé interrompu après 2 h).

---

## 2026-09-14 — Troisième revue du design : signes de l'écart, résidu d'ajustement

**Le résidu d'ajustement est un résultat, pas du bruit.** Une fois le total calé sur
l'O/U 2.5, un seul paramètre de partage ne peut pas reproduire exactement les trois
issues du 1X2 : le résidu mesure ce que deux lois de Poisson corrélées ne savent pas
représenter, le défaut structurel du backtest vu au niveau d'un match. À l'écran, un
écart sur un marché d'ajustement ne signale jamais une opportunité ; il mesure une
contrainte de marché que le modèle n'a pas pu satisfaire. Il reste sans couleur.

**Signes à poids égal.** Un négatif rouge vif face à un positif indistinct fait
ressortir un côté. L'utilisateur a refusé le vert vif dans le tableau et proposé
`--p-hot`. Comparaison à l'écran de quatre paires : contre `--p-hot` (L* 95), le rouge
`#ff2f4d` (L* 56, chroma 84) écrase le positif ; un rose de même luminosité et même
chroma est hors gamut sRGB ; `#ffbab6` (L* 82, chroma 27) est le plus proche qui reste
lisiblement rose, les teintes plus pâles se confondent avec `--p-hot`. Retenu :
`--p-hot` et `--neg` = `#ffbab6`. Le jeton `--red` disparaît.

## 2026-09-14 — Page principale

- **`/dashboard` devient la page principale du terminal** : la route garde son nom
  (redirections de Breeze), l'ancien tableau de bord (compteurs, quota) est supprimé ;
  le quota reste dans `/settings`.
- **Combiné limité à une ligne par match.** Le produit des probabilités n'est juste
  que pour des issues indépendantes ; 1X2 · 1 et DC · 1X, ou BTTS · Oui et Over 2.5,
  ne le sont pas. Afficher leur produit serait afficher une probabilité fausse.
- **Double Chance retirée de la calibration.** Sur le run #8, son Brier est identique
  à celui du 1X2 (0,19173, Pinnacle 0,19103) : chaque probabilité DC est le
  complément d'une issue 1X2, l'erreur quadratique est la même ligne à ligne. Le
  backtest la range dans la famille « dérivés », à tort : ce n'est pas un test
  indépendant. À corriger dans `CalibrationBacktestService` et `ReferenceCalibration`
  à l'étape 4 (rien n'est recalculé ici).
- **« Hors périmètre » exige l'absence de cotes.** Un match de mai 2026 relevé quand le
  périmètre couvrait 21 ligues a des cotes : il est « non calculé », pas hors
  périmètre. Le premier rendu du 02/05 en étiquetait 45 à tort.
- **Monte-Carlo sur la matrice exacte du modèle** (0 à 6 buts, λ et ρ enregistrés).
  L'Over enregistré compte la masse au-delà de 6 buts, les tirages non : l'écart
  simulé / enregistré n'est pas que du bruit, c'est dit en infobulle.
- **Tri par écart au clic non livré.** Tranché ensuite : jamais (entrée suivante).

---

## 2026-09-14 — Aucun tri par écart, même sur les marchés dérivés

Le document de design autorisait le tri par écart au clic. Retiré. Le restreindre aux
marchés dérivés ne respecte pas le principe 1 : il classerait toujours du meilleur au
pire, sur un sous-ensemble. Tris gardés : heure (défaut), championnat, marché, qui
sont des critères de navigation ; filtre par marché. **Toute apparition de l'écart
comme critère de classement est une régression.**

## 2026-09-14 — La Double Chance n'est pas un test indépendant

Constat de la page principale : sur le run #8, le Brier de la DC est identique à celui
du 1X2 au millième près (0,19173, Pinnacle clôture 0,19103). C'est mécanique : chaque
probabilité DC est le complément d'une issue du 1X2 (1X = 1 − P(2)), donc
`(1 − p − (1 − y))² = (p − y)²` ligne à ligne, et la courbe de calibration est le
miroir de celle du 1X2.

**Lecture corrigée des tableaux du backtest** : `CalibrationBacktestService` et
`ReferenceCalibration` rangent la DC dans la famille « dérivés ». Elle n'en est pas
une au sens du test indépendant : c'est une recombinaison du 1X2, qui répète le
contrôle de cohérence. **Les seuls vrais tests indépendants sont BTTS, O/U 1.5 et
O/U 3.5.** Les conclusions de l'étape 2 ne changent pas (elles reposaient sur ces
trois marchés), mais toute ligne « dérivés » qui agrège la DC doit être relue en la
retirant.

À faire à l'étape 4 : sortir la DC de la famille « dérivés » dans le backtest et dans
le résumé de calibration, sans recalculer les runs passés (la famille est une
étiquette, les prédictions stockées restent valides).

---

## 2026-09-14 — Pages restantes du terminal

- **Correctif avant migration** : `analyzeExistingMatch` (bouton de calcul de
  `/analysis`) calculait et écrivait les prédictions sans vérifier le coup d'envoi, et
  « Recalculer tous les matchs » aurait écrasé des prédictions d'avant-match par un
  calcul d'après-match. `predictions:compute` filtrait, pas ce chemin. La garde est
  désormais dans `PredictionService::computeAndStore`, pour tous les appelants
  (`KickoffPassedException`, 409 côté contrôleur). Vérifié en base : aucune
  prédiction dont `computed_at` dépasse le coup d'envoi.
- **Alertes « sharp money » retirées de `/market`.** L'ancienne page affichait un
  score de mouvement, rouge au-delà de 80 : un score qui désigne quoi suivre est une
  décision à la place de l'utilisateur (règle 4). Le calcul existe toujours dans
  `CLVTrackerService` et remplit `odds_movements` ; à supprimer ou garder comme donnée
  brute, à décider.
- **CLV sans couleur.** La couleur de signe est réservée à l'écart du modèle sur un
  marché dérivé ; colorer le CLV par match reviendrait à hiérarchiser les matchs.
- **Bouton de suppression d'un match retiré de l'historique.** Un clic détruisait un
  match, ses prédictions et sa clôture : donnée irremplaçable pour toute mesure. La
  route `DELETE /history/{match}` reste en place, sans interface, en attendant une
  décision.
- **`/analysis/results` redirige** vers le détail du match : la page doublonnait
  `/history/{id}`.
- **Fin des CDN** : Tailwind, Alpine et Chart.js par CDN disparaissent avec
  `layouts.pro` ; la connexion passe au terminal ; les polices Bunny de Breeze sont
  retirées (repli sur la pile système).
- **Lettres grecques en capitales** : sur le détail d'un match et dans le pied de
  page commun, « ρ Dixon-Coles » s'affichait « P DIXON-COLES » et « λ » en « Λ ».
  Corrigé, et l'audit de développement signale désormais toute minuscule grecque
  rendue en capitales (il a aussi trouvé les deux occurrences de la page de
  démonstration).

---

## 2026-09-14 — Score « sharp money » et route de suppression supprimés

**Score et alerte supprimés, variations brutes gardées.** `CLVTrackerService` calculait
un score de 0 à 100 à partir de pondérations écrites à la main (mouvement ≥ 5 % : 30
points, ≥ 8 % : 20 de plus, asymétrie : 25, O/U : 15, ≥ 15 bookmakers : 10) et levait
une alerte à 60 (l'ancienne page `/market` colorait en rouge au-delà de 80). C'est un
verdict, et jamais mesuré : personne n'a vérifié qu'un score élevé prédisait quoi que
ce soit. Dernier reste de l'ancien système. Supprimés : `detectSharpScore`, l'alerte,
leur affichage dans `market:track`. Gardé : chaque relevé et ses variations signées
contre le précédent (`move_home_pct`, `move_draw_pct`, `move_away_pct`,
`move_over_pct`, `snapshot_at`), matière première du test de mouvement de ligne de
l'étape 4 (161 relevés dont 55 avec variation au 14/09/2026). Si ce test montre un
signal, un indicateur mesuré sera construit à ce moment-là.

Les colonnes `sharp_alert` et `sharp_score` ne sont pas supprimées (aucune suppression
de colonne) : elles restent orphelines et ne doivent jamais être lues.

**Route `DELETE /history/{match}` supprimée**, avec `deleteMatch`. Elle détruisait un
match, ses prédictions et sa clôture en un clic. L'intérêt du système est
d'accumuler un historique fiable ; retirer une donnée erronée passera, le jour venu,
par une commande explicite avec confirmation.

**Tests Feature** : `pdo_sqlite` manque toujours sur cette machine, 23 tests sur 24
échouent sur « could not find driver ». Le 24ᵉ était faux depuis l'initialisation du
projet (il attendait un 200 sur `/`, qui redirige vers la connexion) : corrigé, il
passe. Fusion de l'étape 3 faite sans les autres.

---

## 2026-09-14 — Migrations portables, suite de tests complète

`pdo_sqlite` installé, les tests Feature butaient sur la migration
`2026_09_14_000001_add_post_kickoff_data_to_matches_table` : un `UPDATE matches m LEFT
JOIN advanced_data a … SET`, syntaxe MySQL que SQLite refuse. Réécrite avec le
constructeur de requêtes : `created_at >= match_date` OU `EXISTS` d'une ligne
`advanced_data` réécrite après le coup d'envoi.

Équivalence vérifiée avant la réécriture, en lecture seule sur MariaDB : l'ancienne
jointure et la nouvelle requête renvoient les mêmes 913 identifiants, exactement ceux
déjà marqués `post_kickoff_data` (aucune ligne `advanced_data` en double par match,
donc la jointure ne dupliquait rien). Vérifiée aussi sur SQLite avec cinq cas types
(créé après, données réécrites après, avant, sans données, `created_at` nul) :
seuls les deux premiers sont marqués, et la rejouer ne change rien. Chez l'utilisateur
la migration est enregistrée (lot 11) et ne sera pas rejouée ; si elle l'était, la
requête ne pose que des `true` sur les mêmes lignes.

Aucune autre migration n'utilise de SQL brut ; les `->change()` sont gérés par
Laravel 12 sur SQLite. `php artisan test` : 82 tests, tous verts.

Règle qui en découle : une migration n'écrit jamais de SQL propre à un moteur. Les
données se modifient par le constructeur de requêtes.

---

## 2026-09-14 — Relevés du CLV : fin du cache, échec visible, quota réel

**Diagnostic** (zéro relevé à 11:40 et 12:09, cinq ce matin sur les mêmes matchs) :

- Cause : **Pinnacle absent de toute l'API The Odds API**, pas un problème chez nous.
  Appel direct à 12:1x UTC, `bookmakers=pinnacle` : 0 événement sur 13 (Serie A), 21
  (Premier League), 20 (Liga), 9 (Bundesliga), 17 (NFL), y compris les matchs du 20/09.
  Présent dans la réponse de 06:19, absent de celle de 11:40. Pas un retrait avant le
  coup d'envoi ; probablement une interruption technique. Aucune décision prise.
- Ni le cache ni le rapprochement : à 11:40 la requête était fraîche et les cinq
  événements trouvés, sans Pinnacle.
- **Défaut découvert : le cache contaminait `odds_movements`.** Les relevés de 06:57
  et 07:32 reprenaient la réponse de 06:19 (cotes et nombre de bookmakers
  identiques, aucune requête partie) : horodatage faux de 38 et 73 minutes, et une
  variation nulle fictive à chaque relance. C'est la matière première du test de
  mouvement de ligne de l'étape 4, contaminée en direct comme le backfill avait
  contaminé les 913 matchs.
- Échec silencieux : deux passages « réussis » avec zéro relevé, interface à jour.
- Journal : « événement trouvé sans Pinnacle » ne partait que dans `laravel.log`.
- Compteur local de quota en retard de 7 crédits (20 contre 27) : il additionnait les
  coûts de ce serveur et ignorait les appels faits ailleurs avec la même clé.
- Le planificateur ne tourne pas (ni crontab ni `schedule:work`) : aucune clôture
  ne peut être relevée tant qu'il n'est pas lancé.

**Corrections, dans l'ordre de priorité fixé par l'utilisateur :**

1. Relevés sans cache (prédiction et clôture). Nouvelles colonnes
   `odds_movements.quoted_at` (heure de la cote : `last_update` le plus ancien des
   marchés 1X2 et totals du bookmaker) et `reliable`. Un relevé dont la cote a plus
   de `pipeline.odds_snapshot.max_quote_age_minutes` (10) est refusé. Seuil choisi
   sur 1 003 cotes fraîches de la réponse de 11:40 : médiane 0,4 min, p90 1,7 min,
   maximum 5,7 min ; un relevé recyclé en avait 38 à 73. Les variations se calculent
   contre le dernier relevé fiable, la clôture ne vient que d'un relevé fiable.
   **Les relevés existants restent en base avec `reliable` faux : non fiables, ils ne
   servent pas au test de mouvement.** Les cotes de prédiction des cinq matchs du
   14/09 (`matches.odds_at_pred_*`) sont des cotes Pinnacle réelles de 06:19, mais
   `predicted_at` indique 06:57.
   Coût : chaque relevé coûte 2 crédits par championnat, même relancé dans les deux
   heures. C'est le prix d'une observation.
2. `market:track snapshot`, `closing` et `close` sortent en échec quand un match
   attendu n'a pas de relevé (ou de clôture) : passage incomplet dans `pipeline_runs`
   et dans l'interface.
3. Journal du canal `pipeline` : une raison par match (`event_not_found` avec le
   nombre d'événements de la réponse, `bookmaker_absent` avec le nombre de bookmakers
   présents, `stale_quote` avec l'âge de la cote, `no_response`, `quota_exhausted`).
   Plus aucune ligne dans `laravel.log` pour ce cas.
4. Quota The Odds API lu dans les en-têtes de chaque réponse (`x-requests-used`,
   `x-requests-remaining`), compteur local supprimé ; `syncQuota()` le relit sur
   `/sports`, gratuit. Resynchronisé le 14/09 : 27 utilisés, 473 restants.
5. Pinnacle : rien de décidé. Appel de contrôle le 15/09 au matin (1 crédit). S'il est
   durablement parti, le choix d'un autre bookmaker de référence sera documenté avec
   sa conséquence : le CLV ne serait plus mesuré contre l'étalon du backtest.

Tests : 8 tests Feature sur les relevés (Http simulé, SQLite), suite complète 90 tests
verts. Coût du diagnostic : 5 crédits.

---

## 2026-09-14 — Relevés du 14/09 antérieurs à la correction : horodatage décalé

**Les relevés `odds_movements` du 14 septembre 2026 pris avant la correction du cache
ont un horodatage faux.** Leur `snapshot_at` est l'heure d'enregistrement, pas l'heure
de la cote : les cotes venaient de réponses The Odds API mises en cache plus tôt.

Vérifié en base et dans `storage/logs/laravel.log` (application et journal en UTC) :

| Match | Réponse reçue (cote au plus tard) | `matches.predicted_at` | Relevés enregistrés |
|---|---|---|---|
| #1141 Como · Parma | 06:19:19 (Serie A) | 06:57:26 | 06:57:26, 07:32:49 |
| #1142 Torino · AS Roma | 06:19:19 (Serie A) | 06:57:26 | 06:57:26, 07:32:49 |
| #1146 Inter · Udinese | 06:19:19 (Serie A) | 06:57:26 | 06:57:26, 07:32:49 |
| #1148 Leeds · Newcastle | 06:19:28 (Premier League) | 06:57:26 | 06:57:26, 07:32:49 |
| #1149 Villarreal · Real Betis | 06:19:29 (Liga) | 06:57:26 | 06:57:26, 07:32:49 |

- Aucune requête The Odds API entre 06:19:29 et 11:40:42 : les passages de 06:57 et
  07:32 ont lu le cache. Cotes et nombre de bookmakers sont identiques d'un relevé à
  l'autre (24 bookmakers pour la Serie A, 25 pour Leeds, 20 pour Villarreal).
- Décalage de `predicted_at` et du premier relevé : environ 38 minutes (38 min 07 s
  pour la Serie A, 37 min 58 s pour Leeds, 37 min 57 s pour Villarreal). Second
  relevé : environ 73 minutes, et sa variation nulle est fictive.
- L'heure donnée est celle de la réponse de l'API. Le `last_update` propre à Pinnacle
  n'était pas enregistré à l'époque : la cote date de cette réponse au plus tard,
  probablement de une à deux minutes plus tôt (médiane 0,4 min, p90 1,7 min mesurés
  sur une réponse fraîche).

**Ces cinq lignes de `matches` sont datées faussement, mais les cotes sont de vraies
cotes Pinnacle** (`odds_at_pred_home/draw/away` : 1,230/6,340/13,610 ; 6,320/4,300/1,550 ;
1,240/6,480/12,290 ; 2,320/3,530/3,140 ; 2,000/3,880/3,620). Elles restent utilisables
comme cote de prédiction du CLV à condition de retenir 06:19 comme heure, pas
`predicted_at`. Les dix relevés correspondants ont `reliable` faux et ne servent pas au
test de mouvement de ligne. Aucune donnée n'est modifiée.

Cette entrée précise celle qui précède : « cotes Pinnacle réelles de 06:19 » y désigne
l'heure de la réponse de l'API, pas une heure de cote mesurée.

---

## 2026-09-15 — Journal des sélections : une ligne figée par calcul

**Le système calcule des probabilités, mais rien ne vérifiait si elles étaient justes
sur les matchs réels.** Le backtest mesure le modèle sur football-data ; le journal le
mesure en production, avec les cotes Bet365 et le périmètre réel.

Table `prediction_log`, une ligne par prédiction, écrite au moment du calcul :

- **Toutes les lignes calculées**, pas seulement celles que l'utilisateur regarde : un
  journal limité aux sélections remarquées serait biaisé par l'attention. Écrites par
  `PredictionService::computeAndStore` dans la même transaction que `predictions` : un
  calcul affiché mais absent du journal serait un trou silencieux, donc l'échec de l'un
  annule l'autre.
- **Exclues** : les lignes sans cote ou sans probabilité équitable (ensemble de marché
  incomplet), et celles dont la cote est `legacy_max`. Les matchs commencés ou
  contaminés sont refusés par la garde existante ; le modèle revérifie que le calcul
  précède le coup d'envoi.
- **Figée** : `PredictionLogEntry` refuse toute suppression et toute modification, sauf
  les colonnes de clôture encore nulles. Une valeur de clôture ne se réécrit pas.
- **Déclencheur** enregistré : `pipeline` (`predictions:compute`, tous les matchs d'une
  date) ou `manual` (bouton Calculer).
- **Copie de l'identité du match** (équipes, coup d'envoi, championnat) : la clôture
  refuse un match qui n'est plus celui du calcul (reprogrammé, base remise à zéro par
  `app:reset`, qui ne vide jamais le journal).
- **Aucun remplissage rétroactif** depuis `predictions` : ces lignes sont écrasées à
  chaque recalcul et leur déclencheur est inconnu. Le journal démarre vide au premier
  calcul après la migration `2026_09_15_000001`.

Clôture par `log:settle --date=` (la veille par défaut), étape de `pipeline:daily`
juste après `pipeline:run-sync` qui met à jour les scores de la veille. Issue
déterminée par le score final ; **une ligne sans résultat reste en attente, jamais
résolue par défaut**. Match terminé sans score ou changé : échec de la commande. Match
non terminé : avertissement. Limite connue : le score n'est écrit que pour un statut
FT, les matchs AET/PEN restent en attente (non corrigé dans cette étape,
`docs/roadmap.md`).

La règle de clôture Pinnacle (dernier relevé fiable dans les 10 minutes avant le coup
d'envoi) est extraite en `CLVTrackerService::closingSnapshot`, commune au CLV de
`/market` et au journal.

---

## 2026-09-15 — `closing_edge` : le prix jouable contre la clôture Pinnacle équitable

**`closing_edge = cote Bet365 du calcul × probabilité équitable Pinnacle à la clôture − 1`.**
Nommé ainsi pour ne jamais être confondu avec le CLV de `/market`, qui mesure Pinnacle
contre Pinnacle (décision du 14/09/2026, toujours valable pour ce CLV-là).

Ce qui compte pour l'utilisateur est de savoir si le prix qu'il aurait réellement joué
battait la meilleure estimation disponible de la vraie probabilité. Un CLV Pinnacle
contre Pinnacle mesure le mouvement du marché, pas cette performance. Décision de
l'utilisateur.

**Réserve, à relire avant toute lecture des chiffres.** Cet indicateur mélange deux
choses. Bet365 est structurellement moins généreux que Pinnacle, marge retirée : une
valeur systématiquement négative ne prouvera pas que les sélections sont mauvaises, elle
reflétera d'abord l'écart de tarification entre les deux maisons. **Ce qui sera
informatif, c'est la variation de `closing_edge` entre segments, pas son signe
absolu.**

Chaque bookmaker reste unique et identifié (`bookmaker`, `closing_bookmaker`), jamais un
maximum ni une moyenne. Probabilité équitable de clôture : 1X2 normalisé ; double chance
dérivée du 1X2 (pas de cote Pinnacle brute, `closing_odds` nul) ; O/U 2.5 seulement si
Pinnacle publie la ligne 2.5 ; BTTS jamais relevé. **Sans clôture fiable, ou marché non
coté, `closing_edge` reste nul, jamais 0.** Il n'est pas agrégé par `log:report`.

---

## 2026-09-15 — Mesure du journal : premier calcul du pipeline, seuil de 200 matchs

`log:report` mesure les lignes clôturées, sur matchs mesurables.

**Le premier calcul du pipeline de chaque match, et lui seul.** Un match recalculé avec
le bouton compterait double, et les matchs regardés pèseraient plus : exactement le
biais d'attention que le journal doit éviter. Le rapport ne dépend en rien de ce que
l'utilisateur consulte. Les lignes du bouton restent enregistrées et clôturées, mais
hors mesure ; les matchs qui n'ont que des lignes du bouton sont comptés à part, pour
savoir ce qu'on écarte. Décision de l'utilisateur.

**Seuil : 200 matchs clôturés par marché, pas 200 lignes** (`config/prediction-log.php`).
Les trois issues d'un 1X2 ne sont pas indépendantes (elles somment à 1) : les compter
séparément gonflerait l'effectif d'un facteur trois. Toute sortie affiche l'effectif
total ; sous le seuil, chaque bloc dit explicitement que ses chiffres ne permettent
aucune conclusion. Au-dessus, le rapport ne conclut pas davantage : seuil nécessaire,
pas suffisant. **Avec une dizaine de matchs par jour, il faudra des mois. Empêcher de
conclure trop tôt est la principale valeur du journal au début.**

Métriques, par marché puis par championnat, familles séparées comme dans le backtest
(ajustement : 1X2 et O/U 2.5 ; recombinaison du 1X2 : DC, décision du 14/09/2026 ;
dérivé indépendant : BTTS) :

- effectif en matchs et en lignes ;
- Brier du modèle, Brier de la probabilité équitable Bet365 sur les mêmes lignes ;
- différence de Brier appariée, erreur type groupée par match (les lignes d'un match
  sont liées : les traiter comme indépendantes sous-estimerait l'erreur) ;
- fréquence observée par tranche de 5 points, avec effectif.

**Mêmes métriques pour `full_model_probability`** : seule voie pour savoir un jour si les
signaux comparaison et blessures apportent quelque chose, puisque football-data ne les
contient pas. Mesuré sur les seules lignes où l'un des deux a servi : sans eux, le
modèle complet reproduit exactement le marché seul (vérifié dans
`XGModelService::fuseLambdas`), et les inclure diluerait l'écart. Comparé au marché seul
sur les mêmes lignes. **Ces signaux étant souvent sacrifiés au budget d'API-Football,
cet effectif grandira beaucoup plus lentement que le reste** : le rapport affiche sa
propre progression vers le seuil, à côté de celle du marché seul, pour qu'on ne croie
pas dans six mois avoir de quoi trancher.

Tests : 21 tests Feature sur l'enregistrement, la clôture et le rapport (Brier, écart
groupé et tranches calculés à la main), suite complète 111 tests verts.

---

## 2026-09-15 — Incident : la base réelle vidée par la suite de tests

**Le 15/09/2026 à 09:17:46, la base MariaDB `football-analyzer` a été entièrement vidée.**
Toutes les tables ont été supprimées puis recréées vides, les 37 migrations rejouées en
un seul lot.

**Cause.** Après la fusion de `feat/prediction-log`, Claude a lancé `php artisan test`
sur `main` alors que l'utilisateur venait d'exécuter `config:cache`. Avec une
configuration en cache, Laravel ignore les variables de `phpunit.xml` (SQLite en
mémoire) : les tests ont tourné sur la connexion MariaDB en cache, et `RefreshDatabase`
a lancé `migrate:fresh` sur la vraie base. Le signal était dans le message de
l'utilisateur ; les tests ont été lancés sans vérifier `bootstrap/cache/config.php`.

**Perdu, sans retour possible** (binlog MariaDB désactivé, aucune sauvegarde, pas de
récupération sur disque tentée, décision de l'utilisateur) :

- `recommendations` (235 analyses) et `match_validations` (33 matchs validés à la main),
  seule vérité terrain humaine du projet ;
- `matches` (environ 1 140 matchs, dont les cotes Bet365 d'avant-match), `advanced_data`,
  `predictions`, `odds_movements` (relevés Pinnacle, dont les premiers relevés fiables
  du 14/09), `pipeline_runs` ;
- les 30 premières lignes du journal des sélections (3 matchs) ;
- les runs de backtest #1 à #9 en base (`backtest_fd_runs`, `backtest_fd_predictions`).
  Leurs exports JSON et rapports par population restent dans
  `storage/app/private/backtest` : les chiffres documentés ici restent vérifiables ;
- le compte utilisateur.

**Garde** (`tests/TestCase.php`) : la suite s'arrête avant tout trait, donc avant
`RefreshDatabase`, si un cache de configuration existe (fichier par défaut ou
`APP_CONFIG_CACHE`) ou si la connexion de test n'est pas SQLite. Message explicite,
code de sortie 1. Vérifiée sans risque : connexion MariaDB forcée sur une base
inexistante, puis faux cache SQLite hors du projet ; les deux refusent.

**Sauvegarde** : le projet n'en avait aucune, c'est la vraie leçon. `db:backup`, première
étape de `pipeline:daily` : mysqldump compressé dans `storage/app/private/backups`,
fichier horodaté jamais écrasé, mot de passe dans un fichier d'options temporaire et
jamais sur la ligne de commande, vidage incomplet refusé, rotation sur les sept derniers
jours distincts seulement après une sauvegarde réussie. Limite : une semaine de
sauvegardes d'une base vidée finirait par évincer les bonnes ; la rotation ne protège
pas contre un incident découvert tard.

**Reconstruction** :

- schéma à jour, compte recréé ;
- `football-data:import` : 38 780 matchs, 7 822 / 7 830 / 7 800 / 7 681 / 7 647 par
  saison de 2122 à 2526, identique à l'import du 13/09 ;
- backtest de référence relancé : **run #1** (`--sample=work --input=b365
  --divisions=E0,D1,I1,SP1,F1`, Dixon-Coles, ρ unique). ρ estimés identiques au run #8
  (−0,07008 sur 2122, −0,04711 sur 2122-2223) ; par division du Top 5, effectifs, Brier
  et tranches identiques à ceux du run #8. `football-data.reference_run` passe de 8 à 1 ;
  le panneau lit 1X2 Top 5 2223-2324 à 0,19173 contre 0,19103 pour Pinnacle clôture,
  comme avant. **Les numéros #2 à #9 cités dans ce fichier désignent désormais les
  exports JSON, plus des lignes en base.**
- **aucun match passé réimporté** : ils seraient marqués `post_kickoff_data` et exclus de
  toute mesure. Le journal des sélections repart de zéro le 15/09/2026.

**Règle générale : `config:cache` et les tests ne cohabitent jamais.** Avant toute
exécution de la suite, `php artisan config:clear`. Écrite aussi dans `CLAUDE.md`, lu à
chaque session.

---

## 2026-09-15 — Sauvegardes : rotation gardée contre une base rétrécie, vérifiées par restauration

**Limite corrigée.** Avec une rotation sur sept jours seule, une semaine de sauvegardes
d'une base vidée évinçait les bonnes : le scénario du 15/09/2026, avec sept jours de
délai. Règle de l'utilisateur : une base qui rétrécit brutalement est une anomalie, pas
une rotation normale.

Mise en œuvre : **aucune sauvegarde n'est supprimée si la nouvelle fait moins de la
moitié de sa taille** (`pipeline.backup.shrink_ratio`, 0,5). La comparaison porte sur
chaque sauvegarde candidate à la suppression, pas seulement sur la précédente :
comparée à la seule veille, la garde ne protégeait que le premier jour, puisque dès le
deuxième la veille est elle-même la petite sauvegarde. Refus journalisé dans le canal
`pipeline` et `db:backup` en échec, donc passage incomplet visible, à chaque passage
tant que les grosses sauvegardes restent. Conséquence assumée : après une réduction
voulue de la base, les anciennes sauvegardes restent jusqu'à suppression manuelle.

**Une sauvegarde jamais restaurée n'est pas une sauvegarde.** `db:backup --verify`
restaure la dernière sauvegarde dans une base temporaire
(`<base>_verify_AAAAMMJJHHMMSS`, nom dérivé et contrôlé, jamais la base réelle, refus
si elle existe déjà), compte les lignes des tables principales
(`pipeline.backup.verify_tables`) dans la sauvegarde et dans la base réelle, puis
supprime la base temporaire même en cas d'échec et vérifie sa disparition. Échec si une
table manque ou si la base temporaire subsiste.

Premier passage le 15/09/2026, sur la sauvegarde de la base reconstruite : restauration
en 8 secondes, effectifs identiques à la base réelle (`historical_matches` 38 780,
`backtest_fd_predictions` 86 434, `backtest_fd_runs` 1, `users` 1, tables du pipeline
vides), base temporaire supprimée.

---

## 2026-09-26 — Scores rattrapés match par match, lignes non clôturables, jours sans passage visibles

**Constat.** Trois matchs de Liga des 16 et 17/09 sans score, 30 lignes du journal en
attente. Deux causes distinctes :

- Betis-Getafe et Málaga-Villarreal (17/09) : aucun passage le 18 ni le 19. Les scores
  n'étaient cherchés que pour la veille, puis plus jamais. Aucune crontab installée
  (déjà signalé le 15/09) : `pipeline:daily` ne tourne que lancé à la main.
- Levante-Athletic (16/09) : **reporté au 21/10/2026**. Le passage du 17 l'a vu (« 12
  terminés sur 13 ») ; il n'aura jamais de score pour le 16/09.

`log:settle` ne clôturait que la veille : même un score retrouvé plus tard n'aurait rien
clôturé. L'appel de la veille créait aussi les matchs absents de la base (5 le 20/09,
hors du créneau horaire du passage de la veille), aussitôt marqués contaminés.

**Mesuré sur API-Football le 26/09/2026 (offre gratuite, 13 requêtes) :**

| Appel | Résultat |
|---|---|
| `/fixtures?date=` plus ancien que la veille | Refusé : `Free plans do not have access to this date, try from 2026-09-25 to 2026-09-27` |
| `/fixtures?ids=a-b-c` | Refusé : `Free plans do not have access to the Ids parameter` |
| `/fixtures?id=` | Réponse complète à 9, 21, 34, 62, 125 et 153 jours, saison 2025 comprise |

L'idée initiale, balayer plusieurs jours par date, est donc impossible. **Seule voie :
1 requête `/fixtures?id=` par match.**

**Décidé :**

- **Rattrapage** (`FetchMatchDataJob`, après les cotes du jour et l'appel de la veille,
  avant le facultatif) : matchs plus anciens que la veille dont des lignes du journal
  attendent leur clôture, **20 requêtes au plus par passage**
  (`pipeline.score_catchup.max_requests`), jamais sous la réserve du facultatif. Pire
  cas chiffré : dimanche chargé, 1 + 19 cotes + 1 veille + 20 rattrapage + 38
  facultatif = 79 requêtes. Un retard d'un jour de semaine coûte 2 à 4 requêtes, d'un
  jour de week-end une vingtaine.
- **Les plus récents d'abord**, contrairement au plan initial (le plus ancien d'abord) :
  avec une fenêtre longue, vingt matchs que l'API ne termine jamais bloqueraient tout le
  plafond pendant des semaines. Dans cet ordre, ils n'usent que le reste du plafond.
- **Fenêtre de 60 jours** (`pipeline.score_catchup.window_days`), au lieu des 14 validés
  d'abord : `id=` répond encore à 153 jours, et la fenêtre ne coûte rien tant qu'aucun
  match ne reste bloqué. Le volume à rattraper dépend du nombre de jours calculés avant
  l'absence, pas de la durée de l'absence : sans passage, aucun match n'est importé,
  donc aucune ligne n'attend.
- **Lignes non clôturables** (`prediction_log.void_reason`, écrite une seule fois avec
  `settled_at`, issue nulle) : `rescheduled` (même affiche, coup d'envoi déplacé, ou
  statut PST), `cancelled` (CANC), `abandoned` (ABD), `awarded` (AWD, WO),
  `score_unavailable` (toujours sans score 60 jours après le coup d'envoi). Ni mesurées
  ni en attente : `log:report` les compte à part, par raison. Une ligne non clôturable
  ne reçoit jamais d'issue, une ligne clôturée ne devient jamais non clôturable. Des
  équipes différentes (base remise à zéro) restent un échec, pas une annulation.
- **`log:settle` clôture toutes les dates en attente** jusqu'à la veille, la plus
  ancienne d'abord.
- **Statut API-Football conservé** (`matches.api_status`) : la clôture annule sur
  statut sans appel supplémentaire. Un match au statut final sans score n'est plus
  demandé.
- **L'appel de la veille ne crée plus de match** : il ne met à jour que les matchs déjà
  en base, et prend tous leurs statuts (PST, CANC, nouvelle date), plus seulement FT.
- **Match reprogrammé** (`MatchEnricherService::isRescheduled` : pas encore joué, coup
  d'envoi changé) : cotes Bet365, cote de prédiction et clôture du CLV,
  `odds_api_event_id` remis à nul, anciennes valeurs journalisées. Sans cela, un marché
  absent du relevé d'octobre gardait la cote de septembre, et le CLV de `/market`
  comparait la cote de prédiction du 16/09 à la clôture du 21/10. Le premier relevé du
  nouveau coup d'envoi ne calcule aucune variation contre ceux de l'ancien.
  **Vérifié : les relevés de septembre ne peuvent pas servir de clôture en octobre**,
  `closingSnapshot` ne retient que les 10 minutes avant le coup d'envoi en base (test).
- **Premier calcul mesuré par match et coup d'envoi** : les lignes de septembre de
  Levante, non clôturables, ne masquent pas celles d'octobre.
- **Jours sans passage dans `log:report`**, du premier calcul du journal à la veille :
  jours sans aucun passage enregistré dans `pipeline_runs`, et jours aux seuls passages
  échoués ou interrompus. Leurs matchs n'ont jamais été importés : aucun autre
  compteur ne les voit, et ils ne se rattrapent pas (règle 5). Le 26/09/2026 : 18, 19 et
  24/09.

**Laissé ouvert :**

- Match reprogrammé : `predictions` (affichée) et `advanced_data` (blessures,
  comparaison API-Football) gardent les valeurs de l'ancienne date jusqu'au prochain
  calcul et à la prochaine collecte du facultatif. Si le facultatif n'est pas collecté
  le jour du nouveau match (budget), le modèle complet utilisera les blessures de
  l'ancienne date.
- AET/PEN sans score : inchangé (problème connu, `docs/roadmap.md`).
- Crontab toujours absente : le rattrapage répare les scores, pas les jours perdus.

---

## 2026-09-26 — Déploiement sur un VPS : une seule machine collecte

**Contexte.** La collecte ne tournait que lorsque le portable était allumé et le pipeline
lancé à la main : aucune crontab, trois jours perdus entre le 15 et le 26/09. Un VPS
devient la base de référence. Il héberge déjà une autre application en production :
rien de global n'y est modifié (paquets, configuration de MariaDB, fuseau), les scripts
vérifient et s'arrêtent. Procédure : `docs/deploiement.md`.

**Décidé :**

- **Une seule machine collecte, et le code l'impose.** `PIPELINE_ENABLED` (défaut
  `false`) : `pipeline:daily`, `pipeline:run`, `pipeline:run-sync`, `pipeline:backfill`,
  `context:enrich`, `market:track snapshot|closing|close` refusent de tourner
  ailleurs (`App\Support\CollectionGuard`). Le message dit pourquoi (quotas partagés,
  bases divergentes) et quelle machine fait foi. Défaut à `false` : un clone neuf ne
  collecte jamais par accident. Lectures et `log:settle` restent libres.
- **VPS sans serveur web ni build front** : on n'y consulte que la ligne de commande.
  L'interface se lit sur le portable, sur une copie importée.
- **`setup.sh`** idempotent : vérifie (ne jamais installer), `composer install --no-dev`,
  `.env`, `key:generate` si vide, `migrate`, dossiers, compte (`user:create`, mot de
  passe sans écho, jamais en argument). Ni npm, ni `config:cache` (l'incident du
  15/09), ni tests, ni crontab (posée en dernier, après l'import).
- **Transfert** : `scripts/export-production.sh` (données des six tables, sans schéma,
  dans l'ordre des clés étrangères, identifiants conservés) et
  `scripts/import-production.sh` (refus sur tables non vides, comparaison au
  manifeste). **Manifeste plutôt que chiffres fixes** (`data:manifest`) : les effectifs
  changent à chaque passage ; la demande initiale (« 25 matchs, 90 lignes ») ne
  correspondait déjà plus à la base (27 matchs, 270 lignes clôturées, 10 annulées).
  `historical_matches` vient des zips du portable (`football-data:import`), pas du dump.
- **Fuseau : un décalage de 3 h trouvé en répétition.** Le MariaDB du portable est en
  EAT : les `TIMESTAMP` écrits par Laravel (UTC) y sont stockés comme des heures EAT. Un
  `mysqldump` ordinaire les convertissait en UTC réel : sur le VPS, `kickoff_at` des 270
  lignes clôturées ne valait plus `match_date` (`DATETIME`, non converti). Corrigé :
  export `--skip-tz-utc`, import en session UTC, `DB_TIMEZONE=+00:00` sur le VPS (session
  MariaDB fixée par Laravel, sans toucher au réglage global), repères horaires et
  invariant `kickoff_at = match_date` dans le manifeste. Contre-essai : un dump à
  l'ancienne est rejeté par la comparaison. Le portable lit les copies du VPS avec
  `DB_TIMEZONE=+00:00`, son ancienne base sans.
- **`backtest:reference`** : relance la configuration de référence et écrit
  `BACKTEST_REFERENCE_RUN` dans `.env` (sans cache de configuration, pris en compte
  aussitôt), puis compare le Brier 1X2 Top 5 2223-2324 au run #1 du portable (0,19173 ;
  Pinnacle 0,19103). Durée : 8 minutes sur le portable, pas 1 h 40 (chiffre des 22
  divisions). Répété le 26/09/2026 sur une base neuve (migrations, zips, import) :
  mêmes Brier au cinquième chiffre, 9 min 22 s, 109 Mo de mémoire au plus.
- **`system:status`** : dix lignes pour un contrôle par SSH, code de sortie non nul dès
  qu'un voyant n'est pas vert. **Battement du planificateur** écrit chaque minute :
  seul voyant qui aurait signalé l'absence de crontab du 15 au 26/09.
- **Trois clés d'API, pas quatre** : `ANTHROPIC_API_KEY` n'est plus lue depuis le
  14/09/2026 ; absente de `.env.example` et du modèle du VPS (`deploy/env.vps.example`).
- Le rattrapage des scores n'a pas d'entrée propre dans le planificateur : il fait
  partie de `pipeline:daily`. Trois besoins, deux entrées (plus le battement).
- **Fuseau surveillé partout, pas seulement à l'export.** `setup.sh` mesure le décalage
  effectif de la session (`NOW()` contre `UTC_TIMESTAMP()`, pas le nom du fuseau) avant
  `migrate` : un serveur réglé comme le portable l'arrête base vide (vérifié).
  `system:status`, ligne `Fuseaux`, refait cette mesure et vérifie l'invariant des
  données (lignes clôturées : `kickoff_at` = `match_date`) : un changement de fuseau
  ultérieur fait diverger toutes ces lignes d'un coup, critique même sur une copie
  (vérifié : base du portable lue en UTC, 270 lignes).
- **Quelle machine fait foi, d'un coup d'œil** : première ligne de `system:status`
  (`Collecte`) et en-tête de `log:report`. Lu dans `PIPELINE_ENABLED` de la machine
  qui répond.

---

## 2026-09-27 — Structure de la base indépendante du serveur MariaDB

Sur le VPS, `migrate` échouait à la 27e migration (`predictions.computed_at`,
« Invalid default value »). Cause : `explicit_defaults_for_timestamp` vaut 1 sur le
portable (MariaDB 10.11) et 0 sur le VPS (10.6). Réglage global, non modifié : une
autre application tourne sur ce serveur.

- **L'erreur était le moindre mal.** À 0, une colonne `TIMESTAMP NOT NULL` sans défaut
  reçoit en silence `DEFAULT current_timestamp() ON UPDATE current_timestamp()` si
  c'est la première de sa table, un défaut `0000-00-00` refusé par le mode strict
  sinon. Quatre colonnes passaient sans erreur avec un `ON UPDATE` :
  `match_validations.validated_at`, `odds_movements.snapshot_at` (déjà créées sur le
  VPS), `historical_matches.imported_at`, `pipeline_runs.started_at`. Cette dernière
  aurait été réécrite avec l'heure de fin à chaque mise à jour du passage quotidien,
  sans aucun message.
- **`useCurrent()` sur les cinq colonnes** : `timestamp NOT NULL DEFAULT
  current_timestamp()`, identique avec les deux réglages (simulé sur les deux valeurs
  en session, tables temporaires). Écartés : nullable, qui perd la garantie `NOT NULL`
  (choix fait pour `prediction_log`, qui reste tel quel) ; forcer le réglage en session
  depuis Laravel, qui masque l'écart au lieu de rendre les migrations indépendantes, et
  dont l'effet sur 10.6 n'est pas vérifiable d'ici. Aucun changement de comportement :
  le code écrit toujours ces colonnes lui-même, le défaut ne sert jamais.
- **Migrations de création corrigées, plus une migration d'alignement** (`change()`,
  MariaDB seulement, `down()` vide pour ne jamais recréer l'`ON UPDATE`) : elle retire
  l'`ON UPDATE` des deux tables déjà créées sur le VPS et ajoute le défaut sur le
  portable. Le VPS reprend en l'état, sans vider la base : le `CREATE TABLE` échoué
  n'a rien laissé.
- **`sql_mode` : pas de seconde différence pour Laravel.** `NO_ZERO_IN_DATE` et
  `NO_ZERO_DATE` absents du mode global des deux serveurs (identiques) viennent de
  Laravel : `MariaDbConnector` impose à chaque connexion un `sql_mode` fixe dès que
  `strict => true`. Les sessions hors Laravel (`mysql` pour l'import et la
  restauration) reçoivent le leur de l'en-tête du dump. Reste une différence de
  comportement liée au réglage : à 0, un `NULL` écrit dans un `TIMESTAMP NOT NULL`
  devient l'heure courante au lieu d'une erreur. Le code n'en écrit pas.
- **Les écarts de serveur se voient au premier contrôle.** `setup.sh` lit, avant
  toute écriture, la version, `explicit_defaults_for_timestamp` et le `sql_mode` de
  session, et prévient s'ils diffèrent du portable (arrêt si le mode n'est pas strict).
  Puis, après `migrate`, il compare l'**empreinte du schéma** (`SHOW CREATE TABLE` de
  chaque table, `scripts/schema-fingerprint.sh`) à celle du portable versionnée dans
  `deploy/schema-reference.txt`, et s'arrête au premier écart. Une comparaison qu'il
  faut penser à faire n'est pas faite : elle est dans le script. Un test échoue si la
  référence ne couvre pas exactement les migrations du dépôt.

## 2026-09-30 — Trêve internationale, budget API-Football lu dans l'en-tête, ligues nordiques réactivées

Sur le VPS, zéro match importé les 27, 28 et 29/09. Diagnostic avant correction : la
réponse brute de `/fixtures?date=2026-09-30` compte 202 matchs dans 64 ligues, aucune
des 21 suivies ; celles du 29/09 et du 01/10 contiennent la UEFA Nations League, des
amicaux et les qualifications U21 et CAN. **Trêve internationale, pas un défaut** : les
ligues suivies sont absentes de la réponse, aucun filtre ne les rejette. La saison
n'était pas en cause : la requête par date n'envoie pas de paramètre `season`.

Le diagnostic a montré trois défauts annexes, corrigés :

- **Budget lu dans `/status`, qui retarde.** `getDailyUsage()` retenait le plus
  pessimiste du corps de `/status` et du compteur local ; le 30/09, le corps comptait 1
  requête quand l'en-tête `x-ratelimit-requests-remaining` de la même réponse en
  décomptait 3. Le compteur local ne voit pas les appels faits depuis une autre
  machine. L'en-tête de la réponse `/status` entre maintenant dans le maximum (il était
  déjà lu pendant le passage par `lastKnownDailyRemaining()`, pas au départ ni dans
  `system:status` ni sur la page Réglages). Celui d'un appel antérieur n'est jamais
  réutilisé.
- **`inactive_leagues` jamais vidé.** Allsvenskan (113), Superligaen (119) et
  Eliteserien (103), exclues au printemps, devaient revenir en août. Liste vidée, le
  mécanisme reste. **Coût : zéro requête par match**, ces ligues sont hors du
  périmètre des cotes (`odds_leagues`, Top 5) : ni cotes, ni facultatif, pas de ligne
  au journal donc pas de rattrapage, et hors du CLV (`closing.leagues`). Elles
  arrivent dans la requête par date déjà payée. Au plus une requête « scores de la
  veille » un jour où aucune autre ligue n'aurait de match incomplet. Jour le plus
  chargé inchangé : 23 requêtes indispensables, 65 avec le facultatif, 85 avec les 20
  rattrapages au plus. Le quota tient.
- **Outil de diagnostic divergent.** `api-football:test --date` filtrait sur
  `leagues` sans `inactive_leagues` ni créneau horaire, et ne nommait que la première
  ligue ignorée. Le filtrage est maintenant une seule méthode,
  `FetchMatchDataJob::selectFixtures`, appelée par le job et la commande ; celle-ci liste
  toutes les ligues écartées par motif (désactivée, hors créneau, non suivie) et accepte
  `--all` comme `pipeline:run-sync`.

`getUpcomingFixtures` envoyait `season` et `next`, tous deux refusés par l'offre
gratuite : elle lit désormais la fenêtre du jour et du lendemain par date.
`getFixturesByDate` ne prend plus de ligue ni de saison (aucun appelant, et la saison
en cours serait refusée) ; `api-football.default_season` est supprimé.

## 2026-09-30 — Export Markdown des matchs du jour pour une analyse externe

`matches:export-today` écrit un fichier Markdown des matchs du jour, prêt à copier
dans un outil externe. Choix :

- **Lecture seule, après `pipeline:daily`.** Aucune ligne de calcul, de cotes ou de
  pipeline touchée. La probabilité équitable et l'écart sont repris tels que stockés
  dans `predictions` : le lecteur ne refait jamais la démarginalisation, source
  d'erreur classique (probabilité implicite brute prise pour la probabilité équitable).
- **Journée en EAT** (`app.display_timezone`), bornes converties en UTC : un match à
  00h30 EAT appartient au jour EAT, pas à la veille UTC. Matchs `post_kickoff_data`
  exclus (scope `measurable`), comme de toute mesure.
- **Ordre : coup d'envoi croissant**, jamais l'écart (décision du 14/09/2026).
- **Ajustement et dérivés séparés** par `MarketNature`, la classification déjà
  testée de l'interface, avec la mention qui dit pourquoi l'écart des marchés
  d'ajustement est mécanique. Pas de seconde liste à tenir à jour.
- **Une absence dit sa raison.** « non collecté » (contexte ou données facultatives
  jamais relevés), « non disponible (offre gratuite) » quand la raison stockée cite
  l'offre, « non disponible » avec la raison stockée sinon ; une liste de blessés
  vide issue d'un appel réussi s'écrit « aucun absent signalé ». Les absents que l'API
  renvoie en double sont dédoublonnés à l'affichage.
- **Dossier `exports/`**, distinct de `export/` qui sert aux bascules ; nom horodaté
  à la minute EAT. Un jour sans match écrit quand même un fichier qui le dit.
- Pied de page : modèle calibré sur les cotes, qui ne bat pas la clôture Pinnacle ;
  aucune recommandation.
- **Tiret ASCII pour le signe moins**, à la place du U+2212 de l'interface : certains
  outils le lisent mal, et un écart négatif lu comme positif inverserait le sens. Dans
  un fichier fait pour être copié ailleurs, la robustesse prime ; l'interface garde le
  vrai signe moins.
- Validé par l'utilisateur : bookmaker et heure du relevé des cotes par match (sans
  eux, un lecteur externe ne sait pas si la cote a bougé depuis), heure du calcul,
  fichier écrit même un jour sans match, « non calculé » dans une ligne absente.

---

## 2026-10-07 — Zéro match du 27/09 au 07/10 : fenêtre internationale ; garde sur la pagination de /fixtures?date=

Le pipeline n'a gardé aucun match depuis le 27/09. Ce n'est pas un défaut :
`/fixtures?id=` montre que la Premier League passe de la journée 5 (20/09) à la journée 6
(10/10), et que la Liga reprend le 09/10 (journée 8). Il n'y a eu aucun match des
grandes ligues le 3 ou le 4/10 : septembre et octobre forment une seule fenêtre
internationale. `/fixtures?date=` refuse ces dates sur l'offre gratuite (fenêtre J-1 à J+1),
d'où le recours aux identifiants.

La pagination a été vérifiée : 235 matchs le 07/10, `paging.total` = 1. Mais
`getFixturesByDate` ne lisait que `response`. Une réponse paginée aurait donc perdu des
matchs sans aucun message, comme le piège de `/odds?date=`. Choix :

- **Garde plutôt que lecture de toutes les pages.** `paging.total` > 1 lève une
  `ApiFootballException` (API), comme dans `getFixtureOdds`. La boucle sur les pages
  coûterait des requêtes, pour un cas jamais observé, et l'offre gratuite plafonne peut-être
  `page` comme sur `/odds`. Si la garde se déclenche, on mesure d'abord.
- La réponse refusée n'est pas mise en cache. À l'étape 1, l'erreur arrête le job ; pour les
  scores de la veille, elle passe le passage en incomplet, avec un log.
