# Installation

Rustine est distribuée sous forme d’un PHAR exécutable unique (dépendances de production et traductions embarquées ; il
nécessite PHP ≥ 8.4 avec `ext-intl`) :

```bash
curl -fsSL -o /usr/local/bin/rustine https://github.com/umanit/rustine/releases/latest/download/rustine.phar
chmod +x /usr/local/bin/rustine
rustine --version
```

Pour le construire soi-même, ou pour l’exécuter depuis un checkout :

```bash
git clone https://github.com/umanit/rustine.git rustine && cd rustine
composer install
make phar VERSION=1.0.0      # → build/rustine.phar, smoke-tested (needs composer; the version is optional)
bin/rustine list             # or run the sources directly
```
