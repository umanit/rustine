# Development

```bash
composer install
make full_static_analyze   # PHP_CodeSniffer + Rector + PHPStan (max level) + PHPUnit; must pass after any change
```

Tests use real temporary git repositories and a local bare remote, `MockHttpClient` for GitLab and a fake Trivy: no
network. The integration tests run the real Composer, Yarn (Berry and Classic) and git, and are skipped when a tool is
missing. See [`AGENTS.md`](https://github.com/umanit/rustine/blob/main/AGENTS.md) for the conventions and the architecture.
