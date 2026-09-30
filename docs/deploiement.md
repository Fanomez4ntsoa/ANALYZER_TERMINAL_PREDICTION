# Déploiement sur le VPS

> **Contrainte absolue : le VPS héberge déjà une application en production.**
> Rien de global n'est modifié, ni par les scripts, ni par cette procédure : aucune
> installation ni mise à jour de paquet, aucune modification de la configuration de
> MariaDB, de PHP ou du fuseau système, aucune commande `sudo` lancée par un script.
> `setup.sh` vérifie et s'arrête en nommant ce qui manque ; l'installation éventuelle
> se fait à la main, en connaissance de cause. Le projet n'écrit que dans son dossier
> (`/home/deploy/football-analyzer-web`) et dans sa base dédiée. Les seules commandes
> `sudo` de ce guide sont la création de cette base et de son utilisateur (étape 2).

Écrit pour être suivi sans assistance. Chaque étape dit quoi lancer, quoi vérifier,
et quoi faire si ça échoue.

---

## Rôle des machines

| | VPS | Portable |
|---|---|---|
| Rôle | **Base de référence**, seule machine qui collecte | Lecture seule, sur une copie importée |
| `PIPELINE_ENABLED` | `true` | `false` ou absent |
| Crontab | `schedule:run` chaque minute | aucune |
| Commandes | tout, par SSH | `log:report`, `system:status`, interface, tests |

**Règle : une fois le VPS en place, le portable ne lance plus jamais
`pipeline:daily`.** Ni les autres commandes de collecte : `pipeline:run`,
`pipeline:run-sync`, `pipeline:backfill`, `context:enrich`, `market:track snapshot`,
`closing` et `close`. Deux machines qui collectent partagent le quota API-Football
(100 requêtes par jour) et les crédits The Odds API (500 par mois), et leurs bases
divergent sans réconciliation possible.

Ce n'est pas qu'une consigne : ces commandes **refusent de tourner** tant que
`PIPELINE_ENABLED` n'est pas à `true` dans `.env` (`App\Support\CollectionGuard`), et
disent pourquoi. Désactivé par défaut : un clone neuf ne collecte jamais par
accident. Ne jamais mettre `PIPELINE_ENABLED=true` sur le portable pour « dépanner » :
dépanner se fait sur le VPS.

Le VPS n'a **ni serveur web ni build front** : l'interface ne s'y consulte pas. Pour la
voir, importer une copie sur le portable (« Copie vers le portable »).

---

## Vue d'ensemble

| Étape | Où | Durée |
|---|---|---|
| 1. Cloner le dépôt | VPS | 1 min |
| 2. Créer la base dédiée | VPS (`sudo mysql`) | 2 min |
| 3. Écrire `.env` | VPS | 5 min |
| 4. `./setup.sh` | VPS | 2 à 5 min |
| 5. Historique football-data | portable → VPS | 2 min |
| 6. Backtest de référence | VPS | 10 à 25 min (8 sur le portable) |
| 7. **Bascule** : export, import, crontab | portable → VPS | 10 min, **en une seule session** |
| 8. Vérifications du lendemain | VPS | 2 min |

Les étapes 1 à 6 se font à l'avance, sans rien changer au portable, qui continue de
collecter. Seule l'étape 7 le concerne.

---

## Avant de commencer (portable)

- La branche du déploiement est fusionnée dans `main`, et `main` est poussé sur GitHub.
- La suite de tests passe sur le portable : `ls bootstrap/cache/config.php` ne doit
  rien trouver (sinon `php artisan config:clear`), puis `php artisan test`. **Jamais de
  tests sur le VPS.**
- Après la fusion, le portable a `PIPELINE_ENABLED` absent, donc **ne collecte plus**.
  Jusqu'à la bascule, ajouter `PIPELINE_ENABLED=true` dans son `.env` pour qu'il
  continue ; la bascule le retire.

---

## 1. Cloner le dépôt (VPS, utilisateur `deploy`)

```bash
cd /home/deploy
git clone <url du dépôt privé> football-analyzer-web
cd football-analyzer-web
git log --oneline -1
```

**Vérifier** : le dernier commit est celui de `main` sur GitHub.

**Si ça échoue** : authentification GitHub (clé de déploiement ou jeton), à régler
hors de ce projet. Rien d'autre n'a été modifié : recommencer.

---

## 2. Créer la base dédiée (VPS)

Root s'authentifie par socket : `sudo mysql`. Trois bases existent déjà, sans rapport
avec ce projet : **n'y toucher en rien**. Une nouvelle base, un nouvel utilisateur,
des droits limités à cette base.

Choisir un mot de passe long (il ira dans `.env`), puis :

```sql
sudo mysql
CREATE DATABASE `football_analyzer` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'football_analyzer'@'localhost' IDENTIFIED BY '<mot de passe>';
CREATE USER 'football_analyzer'@'127.0.0.1' IDENTIFIED BY '<mot de passe>';
GRANT ALL PRIVILEGES ON `football_analyzer`.* TO 'football_analyzer'@'localhost', 'football_analyzer'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

**Vérifier** :

```bash
mysql -u football_analyzer -p -h 127.0.0.1 football_analyzer -e "SELECT 1; SHOW DATABASES;"
```

`SHOW DATABASES` ne doit montrer que `football_analyzer` (et `information_schema`) :
les autres bases du serveur restent invisibles pour cet utilisateur.

**Si ça échoue** : `Access denied` → mot de passe ou hôte (`127.0.0.1` et `localhost`
sont deux comptes distincts pour MariaDB : les deux sont créés ci-dessus).
`CREATE USER` refusé car l'utilisateur existe → `SELECT user, host FROM mysql.user;`,
puis choisir un autre nom plutôt que de modifier un compte existant.

---

## 3. Écrire `.env` (VPS)

```bash
cp deploy/env.vps.example .env
chmod 600 .env
nano .env
```

Remplir chaque valeur `<...>` :

- `DB_PASSWORD` : celui de l'étape 2 ;
- `API_FOOTBALL_KEY`, `ODDS_API_KEY`, `OPENWEATHERMAP_KEY` : les trois clés du
  `.env` du portable. **Trois, pas quatre** : `ANTHROPIC_API_KEY` n'est plus lue
  depuis la suppression des agents IA (14/09/2026), ne pas la recopier.

Laisser tel quel : `APP_ENV=production`, `APP_DEBUG=false`, `PIPELINE_ENABLED=true`,
`DB_TIMEZONE=+00:00`, `QUEUE_CONNECTION=sync`, `APP_KEY` vide (setup.sh la génère).

**Vérifier** : `grep -c '<' .env` affiche `0`.

---

## 4. `./setup.sh` (VPS)

```bash
./setup.sh
```

Idempotent : relançable autant de fois que nécessaire, il ne refait que ce qui manque.
Il enchaîne :

1. refus de tourner en root ;
2. prérequis **vérifiés, jamais installés** : PHP ≥ 8.2 et ses extensions (ctype, curl,
   dom, fileinfo, filter, hash, mbstring, openssl, pcre, pdo, pdo_mysql, session,
   tokenizer, xml, zip, intl, bcmath), composer, mysql, mysqldump, gzip, sha256sum,
   fuseau système UTC ;
3. `composer install --no-dev --optimize-autoloader` ;
4. `.env` : présence, trois clés d'API, `APP_ENV`, `APP_DEBUG`, `PIPELINE_ENABLED`,
   `DB_TIMEZONE` ; retire un éventuel cache de configuration ; `key:generate` si
   `APP_KEY` est vide ;
5. connexion à la base, puis **décalage effectif de la session MariaDB sur UTC**
   (`NOW()` contre `UTC_TIMESTAMP()`), qui doit valoir 0 : mesuré **avant toute
   écriture en base**, `migrate --force` ne vient qu'après. Un serveur réglé comme le
   portable (EAT) arrête le script ici, base encore vide (vérifié le 26/09/2026) ;
   puis **réglages du serveur** comparés au portable, lus sans rien modifier : version,
   `explicit_defaults_for_timestamp` (1 sur le portable) et `sql_mode` de la session
   Laravel. Un écart affiche `ATTENTION` et le script continue ; un mode non strict
   l'arrête. Après `migrate`, **empreinte du schéma** comparée à celle du portable
   (voir « Empreinte du schéma ») : au premier écart, arrêt avant tout import ;
6. dossiers de `storage/` et `bootstrap/cache`, propriétaire `deploy` ;
7. compte de l'interface : e-mail, puis mot de passe saisi **sans écho**, deux fois
   (`php artisan user:create`, relançable pour changer le mot de passe) ;
8. `system:status`.

Ce qu'il ne fait pas, et pourquoi (aussi écrit en tête du script) :

- **pas de npm ni de build front** : aucun serveur web sur le VPS ;
- **pas de `config:cache`** (ni `optimize`, qui l'inclut) : avec une configuration en
  cache, Laravel ignore `phpunit.xml`, et le 15/09/2026 une suite de tests lancée ainsi
  a vidé la vraie base ;
- **pas de tests** ;
- **pas de crontab** : elle vient en dernier, à l'étape 7.

**Vérifier** : le script finit par « setup.sh terminé ». Dans l'état affiché, sont
**attendus** à ce stade : `Planificateur` et `Pipeline` en CRITIQUE (pas encore de
crontab ni de passage), `Sauvegarde` en CRITIQUE, `Backtest réf.` en ALERTE.
`API-Football` et `The Odds API` doivent afficher un quota : sinon, clé erronée.

**Si `setup.sh` s'arrête** : il affiche `ARRÊT :` suivi de la cause, et n'a rien fait
après ce point. Corriger, relancer.

| Message | Action |
|---|---|
| `commande(s) absente(s) : …` | Installer ces outils vous-même (le script n'installe rien) |
| `extension(s) PHP absente(s) du CLI : …` | Le script donne les paquets probables (`php8.3-…`). Les installer vous-même, en vérifiant qu'ils ne changent rien pour l'autre application (même version de PHP) |
| `fuseau système « … », UTC attendu` | Le serveur n'est pas en UTC. Ne pas le changer sans mesurer l'effet sur l'autre application ; le signaler avant d'aller plus loin |
| `.env absent` ou `.env incomplet` | Étape 3 |
| `connexion impossible à la base` | Étape 2 ; le script réaffiche le SQL de création |
| `session MariaDB décalée de « … » minute(s) sur UTC` | `DB_TIMEZONE=+00:00` manque ou est erroné dans `.env`. Ne pas modifier le réglage global de MariaDB : `DB_TIMEZONE` suffit, il s'applique à chaque connexion du projet |
| `fichier n'appartenant pas à deploy` | Un fichier a été créé par un autre utilisateur (root) : `sudo chown -R deploy: storage bootstrap/cache` dans le dossier du projet |
| Échec de `composer install` | Réseau ou version de PHP ; le message de composer nomme le paquet |
| Échec de `migrate` | Le message nomme la migration ; base vide à ce stade : corriger et relancer |
| `ATTENTION explicit_defaults_for_timestamp = 0` | Attendu sur ce VPS (MariaDB 10.6, réglage global, autre application) : ne pas le modifier. Les migrations en sont indépendantes ; l'empreinte du schéma le vérifie |
| `référence de schéma absente` ou `schéma différent de celui du portable` | Voir « Empreinte du schéma » |

---

## 5. Historique football-data (portable → VPS)

Les zips du portable, pour que le VPS travaille exactement sur les mêmes données (le
site peut avoir modifié ses fichiers depuis).

Portable :

```bash
cd storage/app/private
tar czf /tmp/football-data-zips.tar.gz football-data/*/data.zip
scp /tmp/football-data-zips.tar.gz deploy@<VPS>:/home/deploy/
```

VPS :

```bash
cd /home/deploy/football-analyzer-web/storage/app/private
tar xzf /home/deploy/football-data-zips.tar.gz
cd /home/deploy/football-analyzer-web
php artisan football-data:import
php artisan data:manifest | grep -A3 historical_matches
```

**Vérifier** : chaque saison dit « Zip déjà présent, réutilisé » (aucun
téléchargement), et `historical_matches` compte **38 780** lignes, `max_id` 38 780.
Relançable : l'import met à jour sans dupliquer.

**Si ça échoue** : « Téléchargement … » au lieu de « Zip déjà présent » → les zips ne
sont pas au bon endroit (`storage/app/private/football-data/<saison>/data.zip`).
Effectif différent → comparer à `php artisan data:manifest` sur le portable.

---

## 6. Backtest de référence (VPS)

```bash
php artisan backtest:reference
```

Relance le backtest du panneau de calibration (échantillon de travail, entrée Bet365
ouverture, Top 5, modèle de production), puis écrit son identifiant dans `.env`
(`BACKTEST_REFERENCE_RUN`), faute de quoi le panneau pointerait sur un run absent.
Relançable : si le run désigné est terminé et conforme, il ne recalcule rien
(`--force` pour recalculer).

**Durée** : 8 minutes sur le portable (15/09/2026) ; 9 min 22 s sur une base neuve
importée comme à l'étape 5 (répétition du 26/09/2026, portable chargé par d'autres
essais), pic de mémoire 109 Mo, mêmes Brier au cinquième chiffre. Un seul cœur sert :
sur le VPS (4 vCPU, 7,7 Go), compter **10 à 25 minutes**. Le lancer dans `tmux` ou `screen` pour qu'une coupure SSH ne
l'interrompe pas.

**Vérifier** : la commande finit par

```
1X2 : Brier modèle 0.19173 · Pinnacle clôture 0.19103 (portable, run #1 du 15/09/2026 : 0.19173 · 0.19103)
```

Mêmes chiffres des deux côtés : le calcul est reproduit à l'identique. `grep
BACKTEST_REFERENCE_RUN .env` montre l'identifiant désigné.

**Si ça échoue** : `Écart avec le portable` → l'historique diffère (étape 5).
Commande interrompue → la relancer : le run inachevé n'est jamais désigné.

---

## 7. Bascule — en une seule session

Le portable collecte jusqu'ici. À la fin, le VPS collecte. **Aucune fenêtre où les deux
tournent, aucune où aucun ne tourne.** Tout se fait d'une traite, dans l'ordre.

**Quand** : de préférence **avant 10:00 UTC**, avant le passage quotidien ; le VPS fait
alors lui-même celui du jour. Si la bascule a lieu après 10:00 UTC, voir 7.7.

### 7.1 Portable : arrêter la collecte

Dans le `.env` du portable, mettre `PIPELINE_ENABLED=false` (ou retirer la ligne).
Vérifier qu'aucun passage ne tourne, puis que la garde répond :

```bash
pgrep -af "artisan (pipeline|market:track|schedule)"   # ne doit rien afficher
php artisan pipeline:daily                              # doit refuser et expliquer pourquoi
```

### 7.2 Portable : export

```bash
scripts/export-production.sh
```

Produit `storage/app/private/export/<date>/` : `production.sql.gz` (données des six
tables `matches`, `odds_movements`, `advanced_data`, `predictions`, `prediction_log`,
`pipeline_runs`, sans schéma, dans l'ordre des clés étrangères), `manifest.json`
(effectifs, identifiants maximaux, effectifs du journal, repères horaires) et
`SHA256SUMS`. Le script refuse si le portable collecte encore.

Noter les effectifs du journal qu'il affiche.

### 7.3 Copie

```bash
scp -r storage/app/private/export/<date> deploy@<VPS>:/home/deploy/transfert/
```

### 7.4 VPS : import

```bash
cd /home/deploy/football-analyzer-web
scripts/import-production.sh /home/deploy/transfert/<date>
```

Le script vérifie l'intégrité, **refuse si l'une des six tables n'est pas vide**, importe
avec les identifiants d'origine, puis compare la base au manifeste du portable.

**Vérifier** : « Import conforme au portable. », et les effectifs du journal identiques
à ceux notés en 7.2 (ils changent à chaque passage : jamais de chiffres fixes).

**Si l'import échoue** (avant le point de non-retour, on peut tout reprendre) :

- `fichiers corrompus` → recopier le dossier (7.3) ;
- `la table … contient déjà … ligne(s)` → un passage a tourné sur le VPS avant
  l'import (crontab posée trop tôt ?). Retirer la crontab, puis vider les six tables de
  la base **dédiée** et relancer l'import :

  ```bash
  mysql -u football_analyzer -p -h 127.0.0.1 football_analyzer -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE prediction_log; TRUNCATE predictions; TRUNCATE advanced_data; TRUNCATE odds_movements; TRUNCATE pipeline_runs; TRUNCATE matches; SET FOREIGN_KEY_CHECKS=1;"
  ```

- `la base importée diffère du manifeste` → le script liste chaque écart. Des écarts
  de 3 heures sur les repères `times` : problème de fuseau (voir « Heures et
  fuseaux »), vider les tables comme ci-dessus et refaire l'export avec le script ;
- abandon : sur le portable, remettre `PIPELINE_ENABLED=true`. Il reprend la collecte
  comme avant ; rien n'est perdu.

### 7.5 ─── POINT DE NON-RETOUR : crontab du VPS ───

Jusqu'ici, revenir en arrière consistait à remettre `PIPELINE_ENABLED=true` sur le
portable. **À partir de la ligne ci-dessous, le VPS est la base de référence** : le
portable ne collecte plus jamais, sa base est une archive figée. Un problème se
corrige sur le VPS, jamais en relançant le portable.

```bash
crontab -e
```

Ajouter **une seule ligne** (utilisateur `deploy`, jamais root) :

```
* * * * * cd /home/deploy/football-analyzer-web && php artisan schedule:run >> /dev/null 2>&1
```

Elle suffit à tout, via `routes/console.php` :

| Tâche | Fréquence | Contenu |
|---|---|---|
| `pipeline:daily` | 10:00 UTC | sauvegarde, matchs et cotes du jour, scores de la veille, **rattrapage des scores manqués** (jusqu'à 20 requêtes, 60 jours), clôture du journal, contexte, probabilités, relevé Pinnacle |
| `market:track closing` | toutes les 5 min | relevé Pinnacle dans les 10 minutes avant chaque coup d'envoi |
| `market:track close` | toutes les 5 min | report du dernier relevé en cote de clôture |
| battement | chaque minute | horodatage lu par `system:status` |

Le rattrapage des scores n'a pas d'entrée propre : il fait partie de `pipeline:daily`.

### 7.6 VPS : vérifier que la crontab tourne

Deux minutes après :

```bash
crontab -l
php artisan system:status
```

**Vérifier** : `Planificateur  dernier battement il y a 0 min` ou `1 min`.

**Si le battement n'apparaît pas** : `crontab -l` (ligne présente ?), chemin du projet
dans la ligne, `php` dans le `PATH` de cron (sinon remplacer `php` par le chemin
complet donné par `command -v php`). Pour voir l'erreur, remplacer temporairement
`>> /dev/null` par `>> /home/deploy/cron.log`.

### 7.7 Si la bascule a lieu après 10:00 UTC

Le passage du jour n'aura lieu que demain. Si le portable a déjà fait celui du jour
avant 7.1, rien à faire. Sinon, le lancer à la main sur le VPS, tout de suite :

```bash
php artisan pipeline:daily
```

Les matchs déjà commencés n'auront que leur score (règle 5) ; les autres sont calculés.

---

## 8. Le lendemain (VPS)

Après 10:30 UTC :

```bash
php artisan system:status
```

**Attendu** : première ligne en `OK`. `Pipeline dernier réussi : <aujourd'hui>`,
`Sauvegarde … il y a 0 h`, `Backtest réf. run #… terminé`. Puis, pour s'assurer que
la sauvegarde se restaure :

```bash
php artisan db:backup --verify
```

---

## Contrôle courant, par SSH

```bash
ssh deploy@<VPS> 'cd football-analyzer-web && php artisan system:status'
```

Une ligne par voyant ; code de sortie 1 dès qu'un voyant n'est pas `OK`.

| Ligne | Lit | Alerte quand | Que faire |
|---|---|---|---|
| Collecte | `PIPELINE_ENABLED` de cette machine : base de référence, ou copie de lecture et nom de la base de référence | jamais | — (`log:report` affiche la même information en tête) |
| Planificateur | battement écrit chaque minute par la crontab | plus de 10 min | 7.6 |
| Pipeline | dernier passage (`pipeline_runs`) | aucun passage réussi pour le jour attendu, échec, incomplet | `storage/logs/pipeline-<date>.log` : chaque étape y est journalisée avec sa sortie |
| Jours manqués | jours sans passage depuis le début du journal | un jour manqué dans les 7 derniers | cause dans le journal du pipeline ; un jour manqué est perdu (règle 5), s'assurer qu'il ne se répète pas |
| Journal | effectif clôturé, lignes non clôturables | jamais | — |
| En attente | lignes passées pas encore clôturées | la plus ancienne a plus de 2 jours | le rattrapage la reprend au passage suivant ; si elle persiste : `php artisan log:settle` et son tableau des raisons |
| API-Football | requêtes restantes aujourd'hui (`/status`, gratuit ; plus pessimiste du corps, de l'en-tête et du compteur local) | sous la réserve (10) | normal juste après un samedi chargé ; remise à zéro à minuit UTC |
| The Odds API | crédits du mois (`/sports`, gratuit) | sous 50 : la clôture s'arrête | attendre le mois suivant ; `closing_edge` manquera d'ici là |
| Sauvegarde | dernier fichier dans `storage/app/private/backups` | plus de 26 h | étape `db:backup` du journal du pipeline ; espace disque (`df -h`) |
| Backtest réf. | run désigné dans `.env` | absent ou non terminé | `php artisan backtest:reference` |
| Fuseaux | décalage de la session MariaDB sur UTC ; lignes clôturées du journal dont `kickoff_at` ne vaut plus `match_date` | CRITIQUE si des lignes divergent (heures corrompues, **même sur une copie**) ou si la session n'est pas en UTC malgré `DB_TIMEZONE` ; ALERTE si `DB_TIMEZONE` est absent | « Heures et fuseaux » ci-dessous. Ne rien écrire tant que ce voyant est rouge |

`--offline` saute les deux appels réseau.

Autres lectures : `php artisan log:report` (calibration, jours sans passage),
`tail -n 50 storage/logs/pipeline-$(date -u +%F).log`.

---

## Copie vers le portable (lectures)

Le VPS sauvegarde sa base chaque jour (`db:backup`, sept jours gardés). Pour lire les
données à jour sur le portable, dans l'interface ou avec `log:report` :

```bash
# Portable
scp deploy@<VPS>:/home/deploy/football-analyzer-web/storage/app/private/backups/<dernier>.sql.gz /tmp/
mysql -e "CREATE DATABASE football_analyzer_copie_$(date -u +%Y%m%d) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c /tmp/<dernier>.sql.gz | mysql football_analyzer_copie_$(date -u +%Y%m%d)
```

Puis, dans le `.env` du portable :

```
DB_DATABASE=football_analyzer_copie_<date>
DB_TIMEZONE=+00:00
PIPELINE_ENABLED=false
```

- Une base neuve par copie : jamais d'import par-dessus une base existante. Supprimer
  les anciennes copies à la main quand elles ne servent plus.
- `DB_TIMEZONE=+00:00` est **obligatoire** pour lire une copie du VPS (voir
  ci-dessous). L'ancienne base du portable, `football-analyzer`, archive d'avant la
  bascule, se lit au contraire **sans** `DB_TIMEZONE`.
- Le portable reste en lecture : `log:settle` y tourne, mais ne sert à rien sur une
  copie.

---

## Heures et fuseaux

Laravel écrit toutes ses heures en UTC. MariaDB convertit les colonnes `TIMESTAMP`
(`kickoff_at`, `computed_at`, `settled_at`, `snapshot_at`, `created_at`…) selon le
fuseau **de la session** ; les `DATETIME` (`matches.match_date`) ne sont jamais
converties.

- **VPS** : serveur en UTC et `DB_TIMEZONE=+00:00` : aucune conversion.
- **Portable avant la bascule** : serveur en EAT (UTC+3), pas de `DB_TIMEZONE`. Laravel
  y lit ses heures correctement, mais MariaDB les a stockées comme des heures EAT.
  Un `mysqldump` ordinaire les convertit en « vrai » UTC, décalé de 3 h : constaté le
  26/09/2026, 270 lignes du journal dont `kickoff_at` ne valait plus `match_date`.
  `export-production.sh` passe donc `--skip-tz-utc` (heures telles que Laravel les
  lit), `import-production.sh` importe en session UTC, et le manifeste compare des
  repères horaires en plus des effectifs.
- **Portable après la bascule** : copies du VPS, lues avec `DB_TIMEZONE=+00:00`.

**Surveillé en continu**, pas seulement au transfert :

- `setup.sh` mesure le décalage de session avant toute écriture en base ;
- `system:status`, ligne `Fuseaux`, mesure le même décalage à chaque appel, et vérifie
  les données : une ligne clôturée a été contrôlée égale à son match au moment de la
  clôture, donc `kickoff_at` (TIMESTAMP) doit valoir `match_date` (DATETIME). Si
  quelqu'un change plus tard le fuseau (retrait de `DB_TIMEZONE`, réglage global
  changé sans lui, import mal fait), **toutes** ces lignes divergent d'un coup et la
  ligne passe en CRITIQUE, y compris sur une copie de lecture. Vérifié le 26/09/2026 :
  la base du portable lue en UTC donne 270 lignes divergentes.

---

## Empreinte du schéma

`scripts/schema-fingerprint.sh` écrit la liste des migrations passées puis
`SHOW CREATE TABLE` de chaque table (compteurs `AUTO_INCREMENT` retirés). Lecture
seule. La référence, `deploy/schema-reference.txt`, est l'empreinte du portable,
versionnée. `setup.sh` compare le VPS à elle après chaque `migrate` : **rien à lancer
à la main**.

Pourquoi : les mêmes migrations peuvent donner des structures différentes selon le
serveur. Le 27/09/2026, avec `explicit_defaults_for_timestamp` à 0 sur le VPS, deux
tables y avaient reçu un `ON UPDATE current_timestamp()` que le portable n'avait pas,
sans aucune erreur (`docs/decisions.md`).

**Après toute nouvelle migration** (portable, avant de pousser) :

```bash
php artisan migrate
scripts/schema-fingerprint.sh > deploy/schema-reference.txt
git add deploy/schema-reference.txt
```

Un test échoue tant que la référence ne couvre pas exactement les migrations du dépôt.

**Si `setup.sh` s'arrête sur l'empreinte**, il affiche l'écart (`diff`) :

- seules les lignes sous `# Migrations passées` diffèrent : référence périmée, la
  régénérer sur le portable ;
- une colonne ou une table diffère : la structure dépend du serveur. Ne rien importer,
  corriger la migration (déclarer explicitement ce que le serveur complète de
  lui-même), ajouter si besoin une migration d'alignement, relancer ;
- une différence de pure forme entre MariaDB 10.6 et 10.11 serait visible de la même
  manière : la constater, et ne la tolérer qu'après l'avoir comprise.

Comparer une autre base à la main : `scripts/schema-fingerprint.sh --check
deploy/schema-reference.txt`.

---

## Mettre à jour le code (VPS)

```bash
cd /home/deploy/football-analyzer-web
git pull
./setup.sh
```

`setup.sh` refait `composer install` et `migrate`, compare le schéma au portable, et
vérifie le reste. Éviter la
fenêtre 10:00-10:30 UTC (passage quotidien). Jamais de `config:cache`, jamais de
tests, jamais de `npm`.

---

## Jamais sur le VPS

- modifier quoi que ce soit de global (paquets, configuration de MariaDB ou de PHP,
  fuseau) : autre application en production ;
- `php artisan config:cache`, `php artisan optimize` ;
- `php artisan test`, `phpunit` ;
- `npm` ;
- `PIPELINE_ENABLED=false` ou retirer la crontab « pour voir » : un jour sans passage est
  un jour perdu.
