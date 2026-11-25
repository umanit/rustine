# Prérequis

| Nécessaire                 | Remarques                                                                           |
|----------------------------|-------------------------------------------------------------------------------------|
| PHP ≥ 8.4 avec `ext-intl`  | Pour exécuter Rustine elle-même                                                     |
| `git`                      | Le projet doit être un dépôt git avec un remote configuré                           |
| `trivy`                    | Version compatible avec `--list-all-pkgs` (développé avec la 0.72)                  |
| `node`, `yarn`             | Projets Yarn : dans le `PATH`, ou donnés par `--node-bin` / `--yarn-bin`            |
| `composer`, PHP            | Projets Composer : dans le `PATH`, ou la commande donnée par `--composer-bin`       |

Rustine s’exécute **dans le job de CI du projet lui-même**, pour que `composer update` et `yarn install` utilisent les
mêmes versions de PHP, Node et Yarn que le projet.

## Outils

Rustine **n’installe rien** : l’environnement qui l’exécute doit déjà fournir les outils du projet, dans les versions
attendues par le projet (image de CI, `mise install`, Volta, Corepack…, avant le démarrage de Rustine). Les commandes
sont lancées telles quelles, avec les outils trouvés dans le `PATH`, sauf si `--yarn-bin`, `--node-bin` ou
`--composer-bin` pointent ailleurs.

Avant de corriger un groupe, Rustine contrôle l’environnement et s’arrête avec une erreur explicite quand :

- `node` ou `yarn` (projets Yarn), ou Composer (projets Composer), ne peut pas être lancé ;
- le Yarn de l’environnement ne correspond pas à `yarn.lock` : un lockfile Berry (`__metadata`) demande Yarn 2+, un
  lockfile Classic (`# yarn lockfile v1`) demande Yarn 1. L’échelle de corrections (Berry ou Classic) est choisie à
  partir du lockfile.

Les versions trouvées sont écrites dans la description de la merge request.
