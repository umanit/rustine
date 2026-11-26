# Limitations connues

- Les **monorepos** sont hors périmètre pour l’instant : un seul `composer.json`/`package.json` à la racine du projet.
  Les lockfiles trouvés ailleurs par Trivy (`tools/*/composer.lock`, un sous-projet, `node_modules/`…) sont ignorés et
  listés dans le log du job.
- **Yarn Berry en mode PnP ou avec un cache commité** (`.pnp.cjs`, `.yarn/cache`) est refusé plutôt que géré à moitié.
- Une clé `resolutions` de Berry écrite avec un descripteur (`pkg@npm:^1`) n’est pas reconnue comme équivalente à `pkg`.
- Une entrée `resolutions` est refusée quand le paquet est installé dans plusieurs versions majeures (elle les
  écraserait toutes).
- **Un projet Yarn dont le `package.json` pointe vers une source locale** (`file:`, `link:`, `portal:`) inexistante est
  refusé avec une erreur explicite : Yarn ne peut pas la résoudre. Sauf pour un chemin dans le répertoire vendor de
  Composer, comme le déclare Symfony UX (`file:vendor/symfony/ux-…/assets`) : quand le paquet est dans `composer.lock`,
  Rustine récupère ce seul paquet, à sa version verrouillée et sans ses dépendances (Composer sans plugins ni scripts,
  dans un projet jetable), uniquement pour la passe Yarn, puis le supprime. Pas besoin de `composer install` avant
  Rustine pour ça. Composer doit alors être dans le `PATH`, et les patchs appliqués par un plugin
  (`cweagans/composer-patches`) ne le sont pas.
- **Un paquet Composer vers lequel pointe `package.json` n’est pas rafraîchi dans `yarn.lock`.** Quand une correction
  déplace un tel paquet (`symfony/ux-live-component` pour `file:vendor/symfony/ux-live-component/assets`), Yarn enregistre
  une nouvelle version et un nouveau hash, le lockfile devient périmé et une installation figée
  (`yarn install --immutable`) échoue en CI. Rustine ne corrige que les paquets vulnérables : il le signale dans la
  section « Action manuelle requise » (code de sortie `2`), et la merge request demande un `yarn install` et un commit
  de `yarn.lock` avant d’être fusionnée.
- Un paquet déclaré avec une source git/URL/alias/locale est signalé, jamais corrigé.
- Les montées de version majeure sont signalées, jamais appliquées.
- Une merge request fermée à la main n’est laissée fermée que pendant `--closed-grace-days` jours (30 par défaut), et
  seulement tant que les corrections sont les mêmes. Pour faire taire une CVE définitivement, utiliser un fichier
  `.trivyignore`.
- **Le scan lit le checkout du pipeline, les corrections partent de la pointe de la branche par défaut** (récupérée au
  moment où chaque merge request est construite). Si la branche par défaut a avancé entre les deux, le plan peut avoir un
  commit de retard : ce qui est déjà corrigé est détecté et signalé comme tel, et l’exécution suivante rattrape le
  retard. Lancer le job sur le pipeline de la branche par défaut (`rules: if $CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH`, ou
  une planification) pour limiter l’écart à quelques secondes.
