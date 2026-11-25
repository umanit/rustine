# Développement

```bash
composer install
make full_static_analyze   # PHP_CodeSniffer + Rector + PHPStan (max level) + PHPUnit; must pass after any change
```

Les tests utilisent de vrais dépôts git temporaires et un remote bare local, `MockHttpClient` pour GitLab et un faux
Trivy : pas de réseau. Les tests d’intégration lancent le vrai Composer, Yarn (Berry et Classic) et git, et sont ignorés
quand un outil manque. Voir [`AGENTS.md`](https://github.com/umanit/rustine/blob/main/AGENTS.md)
pour les conventions et l’architecture.
