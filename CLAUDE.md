# CLAUDE.md

Feuille de route courte. Lis ce fichier en début de session. Les détails sont
dans `docs/`, à consulter seulement quand la tâche l'exige.

---

## Ce que fait le système

Il calcule des probabilités par marché pour des matchs de football, à partir
d'un modèle de Poisson dont les paramètres sont estimés sur les cotes.

Il ne décide rien. Pas de verdict BET / NO BET, pas de score de confiance, pas
de niveau. Il affiche la probabilité du modèle, la cote réelle et l'écart entre
les deux. L'utilisateur décide seul de s'engager ou non.

La métrique qui fait foi est la **calibration** : quand le modèle annonce 70 %,
la fréquence observée doit être proche de 70 %. Pas le taux de réussite brut,
qui ne veut rien dire sans la cote associée.

**Stack** : Laravel 12, PHP 8.2, Blade + Tailwind + Alpine, MariaDB.

---

## Règles de travail

1. **Corriger avant d'ajouter.** Aucune fonctionnalité nouvelle sur une base
   buguée.
2. **Montrer avant d'appliquer.** Expose le plan et attends validation sur toute
   modification non triviale.
3. **Une branche par étape.** Voir `docs/git-workflow.md`.
4. **Ne jamais introduire de décision automatique.** Si une règle produit un
   verdict à la place de l'utilisateur, elle n'a pas sa place ici.
5. **Ne jamais utiliser une donnée indisponible avant le coup d'envoi.** Toute
   feature collectée après un match est une fuite temporelle et invalide toute
   mesure faite dessus.
6. **Une cote est celle d'un bookmaker unique et jouable.** Jamais un maximum ni
   une moyenne entre bookmakers.
7. **Documenter à la fin de chaque étape** : une entrée dans `docs/decisions.md`,
   mise à jour de `docs/roadmap.md`. Ne touche pas à ce fichier sauf si une
   règle change.
8. **`config:cache` et les tests ne cohabitent jamais.** Avec une configuration en
   cache, `phpunit.xml` est ignoré et `RefreshDatabase` vide la vraie base : c'est
   arrivé le 15/09/2026, tout a été perdu. Avant de lancer la suite, vérifier
   l'absence de `bootstrap/cache/config.php` (sinon `php artisan config:clear`).
   `tests/TestCase.php` refuse de démarrer dans ce cas : ne jamais contourner
   cette garde.

---

## Documentation

| Fichier | Quand le lire |
|---|---|
| `docs/architecture.md` | Avant de modifier du code : ce qui existe et ce qui a été supprimé |
| `docs/decisions.md` | Avant de remettre en cause un choix : pourquoi il a été fait |
| `docs/roadmap.md` | Pour savoir où on en est et ce qui vient ensuite |
| `docs/design-system.md` | Avant de toucher à l'interface : principes, jetons, vert vif, lueur, états système |
| `docs/git-workflow.md` | Branches, commits, fusions, tags |
| `docs/archive/roadmap-2026-04.md` | Historique. Décrit l'ancien système, supprimé en septembre 2026. Utile uniquement pour retrouver l'origine d'une constante. **Ne décrit pas le système actuel.** |

---

## Commandes

```bash
php artisan pipeline:daily [date]        # Passage quotidien : sauvegarde, import, clôture du journal (veille), contexte, probabilités, snapshot (planifié 10:00 UTC)
php artisan db:backup [--verify]         # mysqldump compressé, sept derniers jours ; --verify restaure la dernière dans une base temporaire
php artisan pipeline:run-sync {date}     # Import matchs (21 ligues) + cotes Bet365 (Top 5)
php artisan predictions:compute [date]   # Probabilités des matchs à venir, non contaminés, avec cotes
php artisan pipeline:backfill --from= --to=
php artisan context:enrich --date=       # Fatigue, enjeux, météo
php artisan market:track snapshot|closing|close|clv
php artisan log:settle --date=           # Clôture du journal des sélections (défaut : la veille)
php artisan log:report [--market=] [--league=]  # Calibration sur matchs réels, seuil 200 matchs par marché
php artisan app:reset [--force]
```

Calcul d'un match à venir : depuis `/analysis`, bouton Calculer (refusé après le coup d'envoi).

---

## État

- Étape 1 terminée le 13/09/2026 : 12 116 lignes supprimées, le système sort des
  probabilités et plus de verdicts.
- Étape 2 terminée le 13/09/2026 : backtest de calibration sur football-data
  (`backtest:run`, `backtest:report`). L'ancien backtest et la page `/backtest`
  sont supprimés.
- Nettoyage du 14/09/2026 : agents IA, combinés et Layer 2 supprimés (le tag
  `etape-2-terminee` conserve leur code). Pipeline quotidien et clôture du CLV
  automatiques. Les 913 matchs marqués `post_kickoff_data` sont exclus de toute
  mesure.
- Étape 3 terminée le 14/09/2026 : interface terminal (`docs/design-system.md`),
  plus aucun CDN, score « sharp money » et route de suppression d'un match supprimés.
- Étape 5 terminée le 15/09/2026 : journal des sélections (`prediction_log`), clôture
  et rapport en commande, rien dans l'interface.
- Incident du 15/09/2026 : base vidée par les tests lancés sur une configuration en
  cache (règle 8). Recommandations, validations manuelles, matchs et relevés perdus ;
  historique football-data réimporté, backtest de référence relancé (run #1), journal
  reparti de zéro. Sauvegarde quotidienne depuis.
- Aucune mesure fiable de performance en conditions réelles n'existe à ce jour.
