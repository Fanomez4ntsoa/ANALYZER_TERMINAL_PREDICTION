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
