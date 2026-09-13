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
