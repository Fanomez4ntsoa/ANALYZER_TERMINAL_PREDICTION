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

Et leurs corollaires :

- Le vert et le rouge indiquent le signe de l'écart, rien d'autre. Un écart arrondi à
  0,0 n'a pas de couleur.
- Une cote absente laisse la cellule vide : aucune estimation.
- Un match hors périmètre des cotes est affiché et étiqueté, jamais omis.
- Le calculateur de combiné affiche le produit des probabilités et le produit des
  cotes. Aucun score, aucune recommandation, aucun commentaire.

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
| `--p-mid` (moyen) | `#86c9a3` | Texte courant, valeurs non vivantes, mesures historiques | 10,2:1 |
| `--p-live` (vif) | `#1cff87` | Valeurs vivantes uniquement | 14,6:1 |
| `--p-hot` | `#b8ffd8` | Flash de la matrice Monte-Carlo, rien d'autre | |
| `--red` | `#ff2f4d` | Écart négatif, rien d'autre | 5,4:1 |

Aucun texte indispensable en vert éteint. Le vert sombre reste sous le seuil WCAG de
4,5:1 à toute taille inférieure à 18 px : on compense par la taille (10 px au lieu de
9), pas par la couleur.

### État système : vidéo inverse

Pipeline pas à jour, écart de configuration, erreur : **fond `--p-live`, texte
`--bg`**. Aucune donnée n'a de fond plein, la confusion est impossible. Pas de couleur
d'alerte supplémentaire.

### Lueur

- **Deux éléments par page au maximum** ; page principale : flash de la matrice
  Monte-Carlo et valeur du CLV.
- Une lueur ne s'applique que par l'attribut `data-glow`. Au chargement, en
  développement, un avertissement console signale plus de trois `[data-glow]`.

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
3. Titre en vert moyen gras, glyphe en vert sombre ; `--p-hot` réservé au flash.
4. Histogramme d'effectifs uniforme, sans barres surlignées.
5. Tri par défaut par heure, suppression de la barre proportionnelle à l'écart.
6. Suppression du bandeau défilant des sélections.

Plus : en-têtes et graduations en vert sombre à 10 px, état système en vidéo inverse,
budget de lueur à deux éléments.

Page de démonstration : artifact « Système de design Terminal Prédiction », publié le
14/09/2026.
