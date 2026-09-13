# Workflow git

Calibré pour un développeur seul, avec de longues interruptions entre deux
sessions. L'objectif est qu'on puisse reprendre le projet après trois mois et
comprendre l'historique d'un coup d'œil.
Et aucun signature de référence à Claude dans la description du commit :
pas de "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>" ou autre, juste moi
---

## Branches

`main` reste toujours dans un état qui tourne. On ne committe jamais dessus
directement.

Une branche par étape ou par fonctionnalité :

```
refactor/predictions-only
feat/backtest-football-data
feat/terminal-ui
fix/odds-bookmaker-label
docs/reorganisation
```

Préfixes : `feat/`, `fix/`, `refactor/`, `docs/`, `chore/`.

---

## Commits

Même préfixe que les branches, un sujet à l'impératif, en dessous de 72
caractères.

```
refactor: supprimer les sources manuelles A/B/C
fix: ne plus écraser les cotes API-Football avec The Odds API
feat: importer les CSV football-data.co.uk
docs: reorganiser CLAUDE.md en docs/
```

Un commit pour un changement cohérent. Ni un commit par fichier, ni un commit
pour toute une étape.

---

## Fusion

Toujours avec `--no-ff` :

```bash
git checkout main
git merge --no-ff refactor/predictions-only
```

Ça crée un commit de fusion explicite, donc l'historique montre où chaque étape
commence et finit. Une fusion en avance rapide dilue les commits dans la masse et
on ne retrouve plus les frontières.

---

## Tags

Un tag à la fin de chaque étape :

```bash
git tag -a etape-1-simplification -m "Système sans verdicts, 12k lignes supprimées"
```

Le jour où une mesure sort un résultat étrange, on veut revenir exactement à
l'état d'avant sans fouiller les logs.

---

## Avant chaque commit

```bash
git status
```

Vérifier qu'aucun fichier de configuration, de log, de cache ou de base de
données n'est indexé. Une clé d'API qui entre dans l'historique y reste même
après suppression du fichier : il faut alors la révoquer et la régénérer.

Pour vérifier que rien de sensible n'est jamais entré :

```bash
git log --all --full-history --name-only -- .env
```

Un résultat vide est la réponse attendue.

---

## Doit rester dans `.gitignore`

```
.env
.env.*
/vendor
/node_modules
/storage/*.key
/storage/logs
/storage/app/private
/public/build
*.sqlite
```
