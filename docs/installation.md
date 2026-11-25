# Installation

Rustine is distributed as a single executable PHAR (production dependencies and translations embedded; it needs PHP
≥ 8.4 with `ext-intl`):

```bash
curl -fsSL -o /usr/local/bin/rustine https://github.com/umanit/rustine/releases/latest/download/rustine.phar
chmod +x /usr/local/bin/rustine
rustine --version
```

To build it yourself, or to run from a checkout:

```bash
git clone https://github.com/umanit/rustine.git rustine && cd rustine
composer install
make phar VERSION=1.0.0      # → build/rustine.phar, smoke-tested (needs composer; the version is optional)
bin/rustine list             # or run the sources directly
```
