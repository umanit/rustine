# AGENTS.md

Rustine is a PHP CLI that opens GitLab merge requests fixing **only** the Composer/Yarn packages
affected by a CVE reported by Trivy. Current state: `scan` (runs Trivy), `plan` (shows the
planned fixes, grouped by MR) and `apply` (Yarn Berry and Classic: applies the fixes to the working tree, no commit/push).
Composer and the GitLab integration are not implemented yet.

## Environment

- PHP `>=8.4`, Symfony Console 8.1, PHPUnit 12, PHPStan level `max`.
- The environment is a mix so that either tool can be used:
    - Nix: `flake.nix` + `.envrc` (direnv) provide the dev shell.
    - Symfony CLI: `.php-version` pins the PHP version (use `symfony php`, `symfony composer`, …).
- No Docker.
- Install dependencies with `composer install`.

## Commands

```bash
make tests                 # vendor/bin/phpunit
make static_analyze        # vendor/bin/phpstan analyse --memory-limit=1G
make full_static_analyze   # static analysis + tests (run after any PHP change)

bin/rustine scan --project=. --output=trivy-report.json
bin/rustine plan --report=trivy-report.json [--group=critical-high] [--format=json]
bin/rustine apply --project=. --report=trivy-report.json --group=others [--dry-run] [--trivy-arg=…]
```

`make full_static_analyze` is the single source of truth: it must pass before a change is
considered done. IDE (PHPStorm) warnings are informational only.

## Architecture (`src/`, namespace `Rustine\`)

| Directory  | Role                                                                      |
|------------|---------------------------------------------------------------------------|
| `Command/` | Console commands (invokable, `#[AsCommand]` / `#[Option]` attributes)     |
| `Trivy/`   | Running Trivy and parsing its JSON report into objects (`TrivyReport`, …) |
| `Version/` | Selecting the smallest fixed version (same major preferred)               |
| `Plan/`    | Planning: grouping fixes by ecosystem and severity group                  |
| `Pinning/` | Detecting pinned tools (`mise.toml` wins over the `volta` key)            |
| `Apply/`   | Applying fixes: strategies, `mise exec` wrapping, git guard-rails, re-scan |

Commands are registered in `src/Application.php`. `tests/` mirrors the structure of `src/`;
fixtures (Trivy reports, the `fake-trivy` binary) live in `tests/fixtures/`.

## Code conventions

- `declare(strict_types=1);` in every file; `final` classes by default; immutable value objects.
- **Never add a `use` statement for native PHP classes.** Reference them with the global
  backslash (`\Exception`, `\Throwable`, `\JSON_THROW_ON_ERROR`, …), even if the IDE or a linter
  suggests otherwise.
- Business errors are dedicated exceptions (`InvalidTrivyReportException`, `PinningException`),
  caught in the commands, which return `Command::FAILURE` / `Command::INVALID`.
- Use precise PHPDoc types for PHPStan `max` (`list<string>`, `array<string, …>`, …).
- Everything is in English: code, identifiers, comments, messages, tests, docs and commits.

## Tests

- Any new behavior is covered by a PHPUnit test in the matching `tests/` directory.
- `failOnWarning` and `failOnRisky` are enabled: a risky test or a warning fails the suite.
- No network calls and no real Trivy in tests: use `tests/fixtures/bin/fake-trivy` and the
  reports in `tests/fixtures/trivy/`.

## Git

- Conventional Commits (`feat:`, `fix:`, `test:`, `chore:`, …) with an explanatory body.
- Never commit `vendor/`, `.phpunit.cache/` or `.direnv/` (already in `.gitignore`).
