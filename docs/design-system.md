# Système de design — Terminal Prédiction

Référence visuelle : `maquette-terminal-v3.html` (commit 2c68882), corrigée par les
décisions ci-dessous. Toutes les pages en héritent : aucune couleur, taille ou
espacement hors de ces jetons.

---

## Principes

Ils valent au-delà des couleurs.

1. **Aucun tri, aucune mise en forme et aucun ordre d'affichage ne doit hiérarchiser
   les sélections par qualité.** Tri par défaut : heure du match. Pas de barre
   proportionnelle à l'écart, pas de mise en avant, pas de bandeau défilant. Le tri
   par écart reste possible au clic, jamais par défaut.
2. **Le vert vif et la lueur signalent une donnée vivante, jamais une mesure
   historique.** Un Brier, une courbe de calibration ou tout résultat de backtest
   s'affichent en vert moyen, sans lueur, sans pastille active.
3. **Un écart mécaniquement nul ne doit jamais être présenté comme une information.**
   En marché seul, les λ sont estimés sur les cotes 1X2 et O/U 2.5 : sur ces marchés
   et sur la Double Chance qui en découle, le modèle ne fait que recalculer son point
   de départ. C'est la distinction du backtest entre contrôle de cohérence et test
   indépendant ; elle doit se lire à l'écran.

Et leurs corollaires :

- Le vert clair et le rose indiquent le signe de l'écart, rien d'autre, avec le même
  poids visuel (voir « Signe de l'écart »). Un écart arrondi à 0,0 n'a pas de couleur.
- **Marchés d'ajustement** (1X2, DC, O/U 2.5) et **marchés dérivés** (BTTS, O/U 1.5,
  O/U 3.5) sont distingués dans chaque tableau par une colonne « Nature »
  (`Ajust.` / `Dérivé`) et une note de bas de tableau. L'écart d'un marché
  d'ajustement ne prend jamais de couleur. Seul l'écart d'un marché dérivé porte un
  signe coloré.
- **Un écart sur un marché d'ajustement ne signale pas une opportunité : il mesure
  l'écart entre le modèle et une contrainte de marché qu'il n'a pas pu satisfaire.**
  Nul sur l'O/U 2.5, qui fixe le total. Sur le 1X2 et la DC, un seul paramètre de
  partage ne peut pas reproduire exactement les trois issues une fois le total calé :
  le résidu mesure ce que deux lois de Poisson corrélées ne savent pas représenter.
  C'est le défaut structurel traqué par le backtest, vu au niveau d'un match (0,58 pt
  en moyenne, 2,74 pts au maximum sur les 33 lignes 1X2 du 14/09/2026). Il s'affiche
  tel quel, sans couleur ni mise en forme compensatoire, et la note du tableau le dit
  (composant `x-terminal.nature-note`).
- Une cote absente laisse la cellule vide : aucune estimation.
- Un match hors périmètre des cotes est affiché et étiqueté, jamais omis.
- Le calculateur de combiné affiche le produit des probabilités et le produit des
  cotes. Aucun score, aucune recommandation, aucun commentaire. **Une ligne par match,
  trois au plus** : le produit suppose des issues indépendantes, deux issues d'un même
  match ne le sont pas (1X2 · 1 et DC · 1X, BTTS · Oui et Over 2.5). Un produit
  faux serait une information fausse.
- La calibration n'affiche pas la Double Chance : ses probabilités sont des sommes de
  celles du 1X2, sa courbe en est le miroir et son Brier est identique au millième
  (0,192 sur le run #8). Le motif est donné en infobulle.

---

## Couleurs

| Jeton | Valeur | Rôle strict | Contraste sur `--panel` |
|---|---|---|---|
| `--bg` | `#040705` | Fond de page | |
| `--panel` | `#080d0a` | Corps de panneau | |
| `--panel-2` | `#0b120e` | En-têtes, barre du haut, pied | |
| `--line` | `#14281d` | Séparateurs | |
| `--line-hi` | `#1e3d2b` | Bordures d'emphase | |
| `--p-dead` (éteint) | `#2e5240` | **Chrome non textuel** : pastilles inactives, barres d'effectif, séparateurs décoratifs | 2,2:1 |
| `--p-dim` (sombre) | `#4e7d63` | Étiquettes, en-têtes de tableau, graduations, à 10 px minimum | 4,1:1 |
| `--p-mid` (moyen) | `#86c9a3` | Texte courant, valeurs des tableaux, mesures historiques | 10,2:1 |
| `--p-live` (vif) | `#1cff87` | Ligne survolée ou cochée, valeur unique d'un panneau | 14,6:1 |
| `--p-hot` (clair) | `#b8ffd8` | Flash de la matrice Monte-Carlo ; écart positif d'un marché dérivé | 17,1:1 |
| `--neg` (rose) | `#ffbab6` | Écart négatif d'un marché dérivé, rien d'autre | 12,1:1 |

Aucun texte indispensable en vert éteint. Le vert sombre reste sous le seuil WCAG de
4,5:1 à toute taille inférieure à 18 px : on compense par la taille (10 px au lieu de
9), pas par la couleur.

### Vert vif : rationnement

Le rationnement vaut pour le vert vif comme pour la lueur. Une valeur vivante n'est
pas automatiquement en vert vif : quand cinquante lignes le sont, la hiérarchie
disparaît.

- **Valeurs d'un tableau** (probabilité du modèle, cote, implicite) : vert moyen.
- **Vert vif** : la ligne survolée ou cochée (ses valeurs passent en vert vif), et les
  valeurs uniques d'un panneau (horloge, produit du combiné, compteurs du
  Monte-Carlo, CLV).
- L'écart n'est jamais en vert vif, même positif : voir ci-dessous.

### Signe de l'écart

Les deux signes ont le même poids visuel. Un négatif rouge vif face à un positif
indistinct ferait ressortir un côté : ce n'est pas neutre.

- Positif : `--p-hot`. Négatif : `--neg`. Neutre (0,0, marché d'ajustement) : vert
  moyen. Les deux signes sont plus clairs que le neutre, et se distinguent entre eux
  par la teinte.
- Choix fait à l'écran le 14/09/2026 entre quatre paires. `--p-hot` contre l'ancien
  rouge `#ff2f4d` (L* 95 contre 56, chroma 32 contre 84) : le rouge écrase le positif.
  Aucun rouge ne peut égaler `--p-hot` en luminosité et en chroma à la fois (hors
  gamut sRGB) ; `#ffbab6` (L* 82, chroma 27) est le plus proche qui reste lisiblement
  rose. Plus pâle (`#ffc8c4`, `#ffd2d0`), il se confond avec `--p-hot`.
- `--p-hot` sert aussi au flash du Monte-Carlo : aucun risque de confusion, le flash
  est une matrice animée, l'écart un chiffre de tableau.

### État système

- **Vidéo inverse** (fond `--p-live`, texte `--bg`) : **un seul bloc par page**,
  réservé à l'anomalie la plus grave. Un avertissement permanent n'avertit plus.
  Aucune donnée n'a de fond plein, la confusion est impossible.
- **Autres états** (pipeline en retard mais passé, écart de configuration secondaire,
  erreur non bloquante) : une ligne en vert sombre, bordure `--line-hi`, message
  tronqué avec texte complet en infobulle.
- Le layout choisit : les états lui sont passés avec une gravité, le plus grave passe
  en vidéo inverse, les autres en ligne. Pas de couleur d'alerte supplémentaire.

### Lueur

- **Deux effets rendus par page au maximum** ; page principale : flash de la matrice
  Monte-Carlo et valeur du CLV.
- Une lueur ne s'applique que par l'attribut `data-glow` ; sur un canvas, le code de
  dessin n'utilise `shadowBlur` que si le canvas porte l'attribut.
- **Le compteur mesure l'effet, pas l'attribut.** En développement, un audit relève
  chaque seconde, dans les styles calculés de chaque élément et de ses
  pseudo-éléments, `text-shadow` (seulement là où il apparaît et s'il porte du
  texte), `box-shadow`, `filter` et les dégradés radiaux, et intercepte `shadowBlur`
  sur les canvas. Avertissement console si plus de deux effets, si un effet n'a pas
  de `data-glow`, si un `data-glow` ne rend rien, ou si plus d'un bloc est en vidéo
  inverse.
- Pas de halo d'ambiance : le dégradé radial vert de la maquette est supprimé (effet
  de page entière, hors budget). Les lignes de balayage restent : elles assombrissent,
  n'éclairent pas, mais donnent aux aplats (barres d'effectif) un aspect de phosphore
  sans aucune lueur mesurable.

---

## Typographie

- **JetBrains Mono** 400, 500, 700 pour tout le texte. **VT323** pour les chiffres
  d'affichage. Embarquées en local (woff2 via le build Vite), jamais depuis un CDN.
- Échelle mono : 10 (étiquettes, en-têtes, graduations), 11,5 (cellules), 12 (texte),
  13 px gras (titre). Étiquettes en capitales, espacement de lettres 0,15 em.
- Échelle VT323 : 24, 34, 42, 58 px ; décimales en petit.
- Chiffres à chasse fixe partout. Format français : virgule décimale, signe moins
  U+2212, probabilités en % à une décimale, cotes à deux décimales, écart en points
  signés à une décimale.

## Espacements

| Jeton | Valeur | Usage |
|---|---|---|
| `--row` | 5 px | Lignes, cellules (vertical) |
| `--hd-y` / `--hd-x` | 6 / 9 px | En-tête de panneau |
| `--gap` | 7 px | Entre panneaux |
| `--page` | 8 px | Marge de page |
| `--bd`, `--cell-x` | 10 px | Corps de panneau, cellules (horizontal) |
| `--group` | 14 px | Groupes dans une barre |

## Page principale

Grille de la maquette v3, tenue dans la hauteur de l'écran à partir de 1100 px :
colonne étroite des chiffres (Brier du marché choisi, combiné, écart de clôture),
Monte-Carlo et calibration en haut, sélections en bas sur deux colonnes. En dessous de
1100 px, un panneau par ligne.

- Le Brier suit le marché choisi dans la calibration : un seul état partagé.
- Monte-Carlo par défaut sur le prochain coup d'envoi calculé, jamais sur un écart.
  Clic sur une rencontre pour la simuler. La frontière tracée sur la matrice est
  l'Under 2.5 exact, en escalier (la maquette traçait le carré 0-2 × 0-2, qui compte
  2-1, 1-2 et 2-2).
- λ et ρ ne passent jamais en capitales : `text-transform` change ρ en Ρ, qui se lit P.

## Mouvement

`html[data-motion="on|off"]`, piloté par un interrupteur dans la barre du haut. Le
réglage système `prefers-reduced-motion` ne fournit que la valeur initiale ; le choix
est ensuite mémorisé. L'attribut gouverne toutes les animations (pastilles, curseur,
Monte-Carlo). La simulation se met en pause quand l'onglet passe en arrière-plan. Au
repos, la matrice affiche les probabilités exactes.

---

## Écarts corrigés par rapport à la maquette (14/09/2026)

1. Brier et courbe de calibration en vert moyen sans lueur (mesures historiques).
2. Pastilles d'en-tête éteintes et fixes, sauf donnée réellement vivante.
3. Titre en vert moyen gras, glyphe en vert sombre ; `--p-hot` réservé au flash (puis à
   l'écart positif, troisième revue).
4. Histogramme d'effectifs uniforme, sans barres surlignées.
5. Tri par défaut par heure, suppression de la barre proportionnelle à l'écart.
6. Suppression du bandeau défilant des sélections.

Plus : en-têtes et graduations en vert sombre à 10 px (variante B retenue, la variante
A de la maquette à 9 px en vert éteint est abandonnée), état système en vidéo inverse,
budget de lueur à deux éléments.

Deuxième revue (14/09/2026) :

7. Un seul bloc en vidéo inverse par page ; les autres états en ligne, vert sombre.
8. Valeurs des tableaux en vert moyen ; vert vif réservé à la ligne survolée ou cochée
   et aux valeurs uniques des panneaux.
9. Compteur de lueurs fondé sur l'effet rendu. L'ancien, fondé sur `[data-glow]`,
   affichait 2/2 alors que la page en rendait 3 : il comptait un `data-glow` sans effet
   (`text-shadow` sur le conteneur du canvas), manquait le `shadowBlur` du canvas et
   le halo radial d'ambiance.
10. Distinction marchés d'ajustement / marchés dérivés (principe 3).

Troisième revue (14/09/2026) :

11. Signe de l'écart à poids égal : `--p-hot` et `--neg`, `--red` supprimé.
12. L'écart d'un marché d'ajustement mesure une contrainte de marché non satisfaite,
    jamais une opportunité.

Page de démonstration : artifact « Système de design Terminal Prédiction », publié le
14/09/2026, version 3.
