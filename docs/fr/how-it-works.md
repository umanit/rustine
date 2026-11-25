# Fonctionnement

À chaque exécution planifiée, pour le ou les écosystèmes et le groupe de sévérité demandés :

1. **Scan** — Trivy analyse le projet (`--list-all-pkgs`, pour distinguer les dépendances directes des transitives). Les
   dépendances installées (`**/vendor`, `**/node_modules`) sont ignorées et la télémétrie est désactivée.
2. **Plan** — les paquets vulnérables sont regroupés par écosystème et groupe de sévérité ; pour chacun, Rustine choisit
   la plus petite version corrigée, dans la même version majeure quand c’est possible (la même version mineure pour
   `0.x`, où une nouvelle mineure est un changement cassant).
3. **Application** — paquet par paquet, les stratégies sont essayées dans l’ordre. Après chaque tentative, le lockfile
   est relu : le changement n’est conservé que si le paquet n’est plus verrouillé à une version vulnérable, sinon les
   fichiers sont restaurés et la stratégie suivante est tentée. Un paquet impossible à corriger ne bloque jamais les
   autres. Trivy ne tourne qu’une fois, quand tous les paquets ont été traités, pour confirmer le résultat : une
   correction dont il signale encore des CVE est marquée pour vérification manuelle.
4. **Publication** — une branche stable est poussée en force et une merge request est ouverte ou mise à jour ; sa
   description liste les CVE, la chaîne de dépendances, la stratégie utilisée et la vérification.

Une merge request existe **si et seulement si au moins une correction a été appliquée**. Tout ce qui ne peut pas être
corrigé automatiquement (montée de version majeure, aucune version corrigée publiée, dépendance git/URL/alias, toutes
les tentatives en échec) est signalé — jamais abandonné en silence — dans la section « ⚠️ Action manuelle requise » de la
merge request, dans le log du job, dans le fichier de rapport et via le code de sortie.

## Une merge request par écosystème, éventuellement découpée par groupe de sévérité

| Exécution                    | Branche                                   | Contenu                        |
|------------------------------|-------------------------------------------|--------------------------------|
| sans `--group`               | `security/rustine-composer`               | Composer, toutes sévérités     |
|                              | `security/rustine-yarn`                   | Yarn, toutes sévérités         |
| `--group=critical-high`      | `security/rustine-composer-critical-high` | Composer, CRITICAL et HIGH     |
|                              | `security/rustine-yarn-critical-high`     | Yarn, CRITICAL et HIGH         |
| `--group=others`             | `security/rustine-composer-others`        | Composer, MEDIUM, LOW, UNKNOWN |
|                              | `security/rustine-yarn-others`            | Yarn, MEDIUM, LOW, UNKNOWN     |

Sans `--group`, tout va dans **une seule** merge request par écosystème. Avec `--group` (répétable), chaque groupe a sa
propre merge request. Un paquet appartient au groupe de la sévérité la plus haute parmi *toutes* ses instances
vulnérables. Trivy tourne toujours avec toutes les sévérités. Un découpage typique lance `critical-high` chaque jour et
`others` une fois par semaine, sous forme de deux planifications ; une seule exécution sans `--group` est plus simple
quand une merge request suffit.

Les branches ne se mélangent pas : passer d’un mode à l’autre ouvre de nouvelles merge requests, et celles des anciennes
branches restent ouvertes jusqu’à ce que quelqu’un les ferme.

## Idempotence

Le nom de la branche est stable : une exécution est donc une réconciliation de ce que la branche doit contenir.

| Situation                                              | Ce que fait Rustine                                                                  |
|--------------------------------------------------------|--------------------------------------------------------------------------------------|
| Pas de merge request, au moins une correction          | Pousse la branche, ouvre la merge request                                            |
| Merge request ouverte, résultat identique              | Rien (les textes et labels sont rafraîchis s’ils sont périmés)                       |
| Merge request ouverte, mêmes corrections, sans conflit | Rien, même si la branche par défaut a avancé (pas de force-push)                     |
| Merge request ouverte, corrections différentes         | Pousse la branche en force, met à jour la description, laisse une note               |
| Merge request ouverte, plus rien à corriger            | La ferme avec une note                                                               |
| Fermée à la main, mêmes corrections, récemment         | Rien : le « non merci » est respecté (`--closed-grace-days`, 30 par défaut)          |
| La branche porte des commits que Rustine n’a pas faits | La laisse intacte, poste une note **une seule fois**, demande une action manuelle    |
| Limite de merge requests ouvertes atteinte             | N’ouvre pas de *nouvelle* merge request (les existantes sont quand même mises à jour) |

La branche est reconstruite depuis la branche par défaut chaque fois qu’elle doit être poussée : elle porte donc
exactement un commit Rustine. « Mêmes corrections » signifie que l’empreinte écrite dans la description de la merge
request (paquets, versions, stratégies) est celle de la nouvelle exécution ; une merge request en conflit est
reconstruite quoi qu’il arrive. Une merge request fermée à la main n’est pas rouverte tant que la nouvelle exécution
proposerait exactement les mêmes corrections (même empreinte) et que la fermeture date de moins de
`--closed-grace-days` jours (30 par défaut, `0` pour toujours en ouvrir une nouvelle). Des corrections différentes, ou
une date plus tardive, ouvrent une nouvelle merge request. Les merge requests que Rustine ferme elle-même (plus rien à
corriger) ne comptent pas : elles portent sa note. Pour faire taire une CVE définitivement, la mettre dans un fichier
`.trivyignore` et passer `--trivy-arg=--ignorefile=.trivyignore`.

## Stratégies de correction

| Écosystème   | Tentatives, dans l’ordre                                                                                                                   |
|--------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| Yarn Berry   | `yarn up -R <pkg>` (rafraîchissement dans les plages existantes) → montée directe de la plage (`yarn up <pkg>@^x`) ou `resolutions` (voir plus bas) |
| Yarn Classic | purge des entrées `<pkg>` de `yarn.lock` puis `yarn install` → montée directe de la plage dans `package.json` ou `resolutions["**/<pkg>"]` |
| Composer     | `composer update <pkg> -W` → `composer require <pkg>:<constraint> -W` (la contrainte garde le style de l’existante : `6.4.*` → `6.5.*`, `~6.3.0` → `~6.5.0`, une version exacte reste exacte, `^` reste `^` ; `--dev` pour `require-dev`) ; en cas d’échec, `composer why-not` est ajouté au log |

La description de la merge request liste ce qui a réellement changé dans le lockfile (versions avant et après), avec les
paquets des corrections en gras et ceux qui ont bougé au passage en texte normal. Avec Yarn Berry 4 ou plus, chaque
tentative tourne avec `--mode=update-lockfile` : Yarn résout et récupère ce qui manque au lockfile mais saute l’étape de
link, si bien que `node_modules` n’est pas reconstruit après chaque tentative de chaque paquet (Trivy ne lit que
`yarn.lock`). Le `yarn install --immutable` final est une installation complète et remet `node_modules` en accord avec
le résultat. Les versions de Berry plus anciennes exécutent les commandes classiques.

Les `resolutions` sont de la dette technique, et sont signalées comme telles dans la description. Un
`yarn install --immutable` / `--frozen-lockfile` / `composer validate` final doit passer avant tout push.

Composer tourne avec `--no-install --no-scripts --no-plugins` : rien n’est installé, et aucun script ni plugin du projet
ne s’exécute (un plugin est du code arbitraire, et Rustine détient un token puissant). Seule exception : un projet qui
définit `extra.symfony.require`, que seul Symfony Flex applique pendant la résolution de Composer : sans Flex, une mise à
jour déplace les composants `symfony/*` que le projet ne requiert pas lui-même vers une autre mineure (`symfony/string`
6.4 → 7.4 dans un projet 6.4). Les plugins sont alors activés et Rustine vérifie que Flex est installé, sinon elle
s’arrête avec une erreur explicite plutôt que de produire cette merge request. Installer Flex **globalement** dans
l’environnement du job :

```bash
composer global config allow-plugins.symfony/flex true && composer global require symfony/flex --no-scripts
```

Seul Flex est alors chargé, et aucun plugin du projet. Un `vendor/symfony/flex` (`composer install --no-scripts`)
fonctionne aussi, mais charge tous les plugins du projet : ils s’exécutent avec les mêmes privilèges que Rustine, qui
peut lire son environnement ; à réserver à un projet dont on fait aux plugins la même confiance qu’à sa CI.

## Garanties de sécurité

- Seuls `composer.json`, `composer.lock`, `package.json` et `yarn.lock` peuvent changer. Toute autre modification
  abandonne la correction, y compris les nouveaux fichiers et répertoires non suivis : ce qu’une correction peut créer
  dans le répertoire du projet (caches, rapports) doit être ignoré par git (`.gitignore` ou `.git/info/exclude`). Git
  signale un répertoire non suivi comme une seule entrée : les fichiers qui apparaissent dans un répertoire déjà présent
  avant la correction (un cache que Trivy ou Yarn remplit) ne sont donc pas un changement.
- Seules les branches nommées `security/rustine-*` sont écrites ; la branche par défaut n’est jamais poussée.
- Les push utilisent `--force-with-lease` sur le commit vu au début de l’exécution.
- Le token n’est jamais placé dans une URL, une ligne de commande ou un log : il circule dans une variable
  d’environnement lue par git (`http.extraHeader`), et est masqué dans tous les messages d’erreur. Il est aussi retiré de
  l’environnement de Yarn, Composer et Trivy. Cela évite les fuites accidentelles, **pas** un programme malveillant :
  un processus tournant sous le même utilisateur peut lire l’environnement de son parent (`/proc/<pid>/environ`).
  L’isolation vient de ce qui a le droit de s’exécuter : voir *Ce qui s’exécute pendant une correction* plus bas.
- Les dépendances déclarées avec une source git/URL/alias/locale (ou une branche `dev-*` pour Composer) ne sont jamais
  remplacées par une version du registre : elles sont signalées pour une correction manuelle.
- Les scripts Yarn sont désactivés pendant les corrections.
