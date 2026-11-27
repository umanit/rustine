# Utilisation

```bash
# Preview what the merge requests would look like, from an existing Trivy report. Touches nothing.
bin/rustine run --dry-run --report=trivy-report.json --group=critical-high --locale=fr

# Real run (what the CI job does): scan, fix, push, open/update/close merge requests.
RUSTINE_GITLAB_TOKEN=… bin/rustine run --group=critical-high

# Building blocks
bin/rustine scan  --project=. --output=trivy-report.json
bin/rustine plan  --report=trivy-report.json --group=others [--format=json]
bin/rustine apply --project=. --report=trivy-report.json --group=others --ecosystem=yarn [--dry-run]
```

`apply` modifie l’arbre de travail du projet (ni commit, ni push) : à essayer uniquement sur une copie jetable.

## Suivre une exécution

Composer, Yarn, git et Trivy n’affichent rien d’eux-mêmes : une exécution peut donc sembler inactive un moment.
`run -v` écrit une ligne par étape avec les secondes écoulées depuis le début : récupération, départ de la branche par
défaut, chaque paquet et chaque tentative, le contrôle Trivy, le contrôle de cohérence, le push et la merge request.

```
security/rustine-composer
-------------------------
  [  0s] Looking for an open merge request
  [  1s] Fetching the default branch and the state of security/rustine-composer
  [  2s] Starting from the default branch
  [  2s] Checking the tools of the environment
  [  3s] Checking the project before the fixes
  [  4s] Fixing symfony/http-client v8.1.9 → 8.1.11 (1/3)
  [  4s]   attempt: lockfile-refresh
  [  9s] Skipping symfony/mime v8.1.7 (2/3): already fixed
  [ 11s] Verifying the fixes with Trivy
  [ 19s] Checking that the lockfile is consistent
  [ 21s] Committing the fixes
  [ 23s] Opening the merge request
```

## Dry run

`run --dry-run` lit un rapport Trivy et affiche, pour chaque groupe, le titre, la sévérité, le message de commit et la
description de la merge request qui *serait* ouverte. Rien n’est exécuté : toutes les corrections sont donc présentées
comme *prévues, non vérifiées*. Ajouter `--report-file=preview.md` pour conserver la sortie.

## Configuration de `run`

Les options priment sur le fichier de configuration (voir plus bas), le fichier sur les variables, et les variables sur
les valeurs par défaut. Les options répétables (`--group`,
`--label`, `--assignee`, `--reviewer`, `--notify`) lisent leur variable comme des valeurs séparées par des virgules
(`RUSTINE_ASSIGNEE=alice,bob`), sauf `RUSTINE_TRIVY_ARG`, séparée par des espaces car les arguments de Trivy contiennent
des virgules (`--severity=HIGH,CRITICAL`). `RUSTINE_MAX_OPEN_MERGE_REQUESTS` et `RUSTINE_CLOSED_GRACE_DAYS` doivent être
des entiers positifs ou nuls. `RUSTINE_GROUP` et `RUSTINE_ECOSYSTEM` ne s’appliquent qu’à `run` ; `RUSTINE_TRIVY_BIN` et
`RUSTINE_TRIVY_ARG` valent aussi pour `scan` et `apply`.

### Fichier de configuration

Les réglages non sensibles d’un projet peuvent vivre dans un fichier YAML versionné avec lui, pratique quand la pipeline
qui exécute Rustine n’appartient pas au projet (elle ne peut pas connaître ses relecteurs). Rustine lit `.rustine.yaml`
à la racine de `--project` quand il existe ; `--config=<chemin>` ou `RUSTINE_CONFIG=<chemin>` désignent un autre fichier
(il doit alors exister). `run`, `scan` et `apply` l’acceptent ; `scan` et `apply` n’utilisent que `include_dev`,
`trivy_args` et (`apply`) `verify`.


Un [JSON Schema](https://raw.githubusercontent.com/umanit/rustine/v0.6.0/schema/rustine.schema.json) décrit le fichier : avec la modeline ci-dessous (première ligne du fichier), les éditeurs qui
s’appuient sur `yaml-language-server` (VS Code avec l’extension YAML de Red Hat, Neovim, Helix, PhpStorm…) proposent
l’autocomplétion, affichent la description de chaque clé et signalent les clés inconnues ou les mauvais types pendant la
saisie.

L’URL est figée sur un tag de release, donc le schéma correspond toujours à la version que vous exécutez (disponible
à partir de `v0.6.0`) : changez le tag quand vous mettez Rustine à jour.

```yaml
# yaml-language-server: $schema=https://raw.githubusercontent.com/umanit/rustine/v0.6.0/schema/rustine.schema.json
assignee: [alice]
reviewer: [bob, carol]
notify: [my-group/security]
labels: [security, deps]
severity_label_format: 'severity::{severity}'
locale: fr
branch: main                 # branch to analyze (default: the default branch)
ecosystem: composer          # yarn or composer
group: [others]              # critical-high or others
max_open_merge_requests: 3
closed_grace_days: 30
include_dev: false
verify: final                # each, final or none
trivy_args: ['--ignorefile=.trivyignore']
git_author_name: Rustine
git_author_email: rustine@example.com
```

Chaque clé correspond à l’option du même nom (`labels` est `--label`, `trivy_args` est `--trivy-arg`) ; les listes sont de
vraies listes YAML, et une liste vide l’emporte sur la variable. Une clé inconnue ou une valeur du mauvais type arrête la
commande : une faute de frappe ne passe jamais inaperçue. Rien de sensible ni de lié à la machine n’est lu dans le
fichier : le token, l’adresse et le projet GitLab, les binaires (`--*-bin`) et le remote restent dans les options et
l’environnement du job.

| Option                       | Variable                                 | Défaut / signification                                                                                                                                                                                         |
|------------------------------|------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--group`                    | `RUSTINE_GROUP` | `critical-high` ou `others` (répétable ; `run` : par défaut tous les groupes dans une merge request par écosystème ; `apply` : exactement un ; `RUSTINE_GROUP` les prend séparés par des virgules) |
| `--ecosystem`                | `RUSTINE_ECOSYSTEM` | `yarn` ou `composer` (défaut : les deux)                                                                                                                                                                       |
| `--project`                  |                                          | Répertoire du projet (défaut `.`)                                                                                                                                                                              |
| `--gitlab-url`               | `CI_API_V4_URL`                          | Racine de l’API GitLab, ou adresse de l’instance (`/api/v4` est ajouté s’il manque)                                                                                                                            |
| `--project-id`               | `CI_PROJECT_ID`                          | Id ou chemin du projet                                                                                                                                                                                         |
| `--branch`, `--default-branch`| `RUSTINE_BRANCH`, puis `CI_DEFAULT_BRANCH`| Branche à analyser : les corrections en partent et les merge requests la ciblent (défaut : la branche par défaut du projet)                                                                                    |
| *(pas d’option, volontairement)* | `RUSTINE_GITLAB_TOKEN` ou `GITLAB_TOKEN` | Token d’accès                                                                                                                                                                                              |
| `--locale`                   | `RUSTINE_LOCALE`                         | Langue des textes écrits dans le projet (défaut `en`)                                                                                                                                                          |
| `--label`                    | `RUSTINE_LABEL` | Label de chaque merge request (répétable, défaut `security`)                                                                                                                                                   |
| `--severity-label-format`    | `RUSTINE_SEVERITY_LABEL_FORMAT` | Défaut `severity::{severity}` → `severity::high`                                                                                                                                                               |
| `--assignee`, `--reviewer`   | `RUSTINE_ASSIGNEE`, `RUSTINE_REVIEWER` | Noms d’utilisateur, appliqués quand une merge request est **créée**                                                                                                                                            |
| `--notify`                   | `RUSTINE_NOTIFY` | Noms d’utilisateur ou de groupes (répétable) mentionnés (`@name`) dans la description pour les notifier, sans assigner la merge request                                                                       |
| `--max-open-merge-requests`  | `RUSTINE_MAX_OPEN_MERGE_REQUESTS` | Cesse d’ouvrir de nouvelles merge requests au-delà de ce nombre                                                                                                                                                |
| `--closed-grace-days`        | `RUSTINE_CLOSED_GRACE_DAYS` | Ne rouvre pas une merge request fermée à la main pour les mêmes corrections depuis moins de ce nombre de jours (défaut `30`, `0` = toujours en ouvrir une)                                                     |
| `--git-author-name/-email`   | `RUSTINE_GIT_AUTHOR_NAME`, `RUSTINE_GIT_AUTHOR_EMAIL` | Défaut : le propriétaire du token (email de commit, sinon son email privé GitLab `<id>-<user>@users.noreply.<host>`)                                                                                           |
| `--remote`                   | `RUSTINE_REMOTE` | Remote git (défaut `origin`)                                                                                                                                                                                   |
| `--report-file`              |                                          | Rapport Markdown : statut et description de chaque groupe                                                                                                                                                      |
| `--report`                   |                                          | Rapport Trivy : écrit par une exécution réelle, lu par `--dry-run`                                                                                                                                             |
| `--composer-bin`             | `RUSTINE_COMPOSER_BIN`                   | Commande qui lance Composer (défaut `composer`), p. ex. `/usr/bin/php8.1 /usr/local/bin/composer2` pour choisir la version de PHP ; mots séparés par des espaces, sans quoting                                  |
| `--yarn-bin`                 | `RUSTINE_YARN_BIN`                       | Commande qui lance Yarn (défaut `yarn`), p. ex. `/opt/yarn/bin/yarn` ; mots séparés par des espaces, sans quoting                                                                                              |
| `--node-bin`                 | `RUSTINE_NODE_BIN`                       | Commande qui lance Node (défaut `node`) ; donnée par chemin, son répertoire passe en premier dans le `PATH` de Yarn                                                                                            |
| `--verify`                   | `RUSTINE_VERIFY`                         | Quand Trivy confirme les corrections : `final` (défaut, une fois quand tous les paquets sont traités), `each` (après chaque tentative acceptée par le lockfile : lent, le plus sûr) ou `none` (le lockfile seul décide) |
| `--include-dev`              | `RUSTINE_INCLUDE_DEV`                    | Analyse aussi les dépendances de développement (`require-dev`, `devDependencies`) : voir plus bas. Désactivé par défaut                                                                                        |
| `--trivy-bin`, `--trivy-arg` | `RUSTINE_TRIVY_BIN`, `RUSTINE_TRIVY_ARG` | Binaire Trivy et arguments supplémentaires (répétable), p. ex. `--trivy-arg=--ignorefile=.trivyignore`                                                                                                         |

Les labels ne sont jamais traduits (les filtres, tableaux et la limite de merge requests ouvertes dépendent de leurs
noms), pas plus que les noms de branches, identifiants de CVE, versions ou commandes.

## Dépendances de développement

Trivy laisse de côté les dépendances de développement par défaut, et Rustine aussi : un paquet vulnérable de
`require-dev` n’obtient aucune merge request. `--include-dev` (ou `RUSTINE_INCLUDE_DEV=1`) est une option qui ajoute
`--include-dev-deps` à chaque exécution de Trivy, le scan comme les re-scans. Ces paquets sont alors corrigés comme les
autres (une contrainte `require-dev` est relevée avec `composer require --dev`) et listés dans la même merge request.
Elle est acceptée par `scan`, `run` et `apply` : la donner aussi à `apply` quand son rapport a été fait avec, sinon les
re-scans ne voient pas les dépendances de développement.

Observé avec Trivy 0.72 : il marque les paquets de développement d’un `composer.lock` (`Dev: true`), alors que les
résultats sur un projet Yarn étaient les mêmes avec et sans l’option.

## Codes de sortie

| Code | Signification                                                                                                                         |
|------|---------------------------------------------------------------------------------------------------------------------------------------|
| `0`  | Terminé, rien ne reste pour un humain                                                                                                 |
| `1`  | Un groupe a échoué (git, GitLab, cohérence du lockfile…) ou la commande est mal utilisée                                              |
| `2`  | Tout ce qui pouvait être corrigé l’a été ; certains paquets demandent une action manuelle, ou un groupe a été ignoré (limite, commits étrangers) |

Dans GitLab CI, `allow_failure: { exit_codes: [2] }` transforme le code `2` en job orange plutôt que rouge.

## Créer le token

Rustine a besoin d’un token parce que `CI_JOB_TOKEN` ne peut ni pousser de branches ni ouvrir de merge requests.

1. Créer un **access token** avec le rôle **Developer** et les scopes `api` et `write_repository` : soit un **project
   access token** par projet, soit un **group access token** pour tous (le token d’un utilisateur bot fonctionne aussi).
2. Le stocker dans une variable CI/CD **masquée et protégée** nommée `RUSTINE_GITLAB_TOKEN`, au niveau du token.
3. Les merge requests et les notes sont attribuées au propriétaire du token. Les commits ont aussi besoin d’une identité :
   elle est lue chez le propriétaire du token (`GET /user`), ou définie avec `--git-author-name` /
   `--git-author-email`.

Les variables protégées ne sont exposées qu’aux refs protégées : lancer la planification sur la branche par défaut.

**Token de groupe ou de projet ?** Un token de groupe est pratique (une variable, une date d’expiration), mais il a le
scope `api` sur **tous** les projets du groupe, et quiconque peut modifier le `.gitlab-ci.yml` d’une branche protégée de
*n’importe lequel* de ces projets peut lui faire afficher le token par un job. Un token de projet limite les dégâts d’une
fuite à un seul projet, au prix d’un token à créer et renouveler par projet. Préférer les tokens de projet pour les
projets sensibles, et donner une date d’expiration à chaque token.

## Ce qui s’exécute pendant une correction

Rustine lance les outils du projet, et donc le code qu’ils chargent. Il faut savoir de quoi il s’agit avant de lui
confier un token :

| Outil    | Code du projet qui s’exécute                                                                                                                                                                                                                |
|----------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Yarn     | Les scripts de cycle de vie sont désactivés, mais les plugins listés dans `.yarnrc.yml` sont chargés (ils sont commités dans `.yarn/plugins/`)                                                                                              |
| Composer | Scripts et plugins sont désactivés. Pour un projet qui définit `extra.symfony.require`, les plugins sont activés pour laisser Symfony Flex travailler : un Flex installé globalement est le seul plugin chargé, un `vendor/` charge aussi les plugins du projet |
| Trivy    | Aucun : il ne lit que les lockfiles                                                                                                                                                                                                         |

Le code vient de la branche par défaut (le job s’y exécute) : c’est donc du code déjà mergé, et les plugins qu’il peut
charger sont ceux auxquels le projet fait déjà confiance dans sa propre CI. La différence avec les autres jobs, c’est
le token : garder la planification sur la branche par défaut, et une variable protégée.

## Exemple de job

Rustine ne fournit pas de template CI ; un job minimal ressemble à ceci, à adapter à votre propre CI. Préparer les
outils est le travail de la CI, pas de Rustine : ici `mise` installe les versions du projet avant le démarrage de
Rustine (une image qui les embarque déjà fonctionne aussi).

```yaml
security-bot:critical-high:
    stage: .post
    rules:
        -   if: $CI_PIPELINE_SOURCE == "schedule" && $SECURITY_BOT == "true"
    variables:
        GIT_DEPTH: "0" # the branch ownership check needs the history
        RUSTINE_LOCALE: fr
        TRIVY_CACHE_DIR: $CI_PROJECT_DIR/.trivy-cache
        MISE_DATA_DIR: $CI_PROJECT_DIR/.mise
    script:
        # The caches live in the project directory (GitLab only caches paths there): hide them from git, otherwise the
        # files they gain during a fix (e.g. the Trivy cache filled by the final scan) abandon it as an unexpected change.
        - printf '%s\n' .mise/ .trivy-cache/ trivy-report.json rustine-report.md >> .git/info/exclude
        - mise install
        - eval "$(mise env -s bash)"
        - rustine run --group=critical-high --report-file=rustine-report.md
    allow_failure:
        exit_codes: [2]
    artifacts:
        when: always
        paths: [rustine-report.md]
    cache:
        paths: [.trivy-cache/, .mise/]
```
