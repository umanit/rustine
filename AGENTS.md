# AGENTS.md

Rustine is a PHP CLI that opens GitLab merge requests fixing **only** the Composer/Yarn packages
affected by a CVE reported by Trivy. Commands: `scan` (runs Trivy), `plan` (shows the planned fixes, grouped by MR),
`apply` (Yarn Berry/Classic and Composer: applies the fixes to the working tree, no commit/push) and `run` (the CI entry
point: scan → plan → apply → commit → push → merge request; `--dry-run` only previews the texts from a report).
The CI template is not written yet; the PHAR is built by `make phar`.

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
make phar [VERSION=x.y.z]  # build/rustine.phar (tools/build-phar.php): clean `composer install --no-dev` staging, smoke test

bin/rustine scan --project=. --output=trivy-report.json
bin/rustine plan --report=trivy-report.json [--group=critical-high] [--format=json]
bin/rustine run --report=trivy-report.json --group=others [--locale=fr] [--report-file=report.md] --dry-run
RUSTINE_GITLAB_TOKEN=… bin/rustine run --group=others [--ecosystem=yarn] [--gitlab-url=… --project-id=… --default-branch=…]  # real run; exit code 2 = manual action needed
bin/rustine apply --project=. --report=trivy-report.json --group=others [--ecosystem=yarn|composer] [--dry-run] [--trivy-arg=…]
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
| `Apply/`   | Applying fixes: strategies, tool checks (nothing installed), git guard-rails, lockfile check per attempt, one final re-scan |
| `Git/`     | Branch of one MR: inspect the remote, restart from the default branch, commit the allowed files, lease-guarded push; token kept out of argv and logs |
| `GitLab/`  | REST v4 client (`GitLabClient` + `HttpGitLabClient`), `GitLabConfig` (options, then CI variables; token from env only) |
| `Sync/`    | `MergeRequestSynchronizer` (one plan: branch + MR state machine), `NoteRenderer`, `SyncSettings`, `RunSummary` (report + exit code) |
| `Report/`  | Title, description and commit message of a merge request (localized)       |
| `I18n/`    | `TranslatorFactory` (symfony/translation); catalogs in `resources/translations/` |

Commands are registered in `src/Application.php`. Code must work inside the PHAR: no `glob()` (it does not support `phar://`),
no path computed outside `__DIR__`-relative resources; `resources/` is embedded. `tests/` mirrors the structure of `src/`;
fixtures (Trivy reports, the `fake-trivy` binary) live in `tests/fixtures/`.

## Code conventions

- `declare(strict_types=1);` in every file; `final` classes by default; immutable value objects.
- **Never add a `use` statement for native PHP classes.** Reference them with the global
  backslash (`\Exception`, `\Throwable`, `\JSON_THROW_ON_ERROR`, …), even if the IDE or a linter
  suggests otherwise.
- Business errors are dedicated exceptions (`InvalidTrivyReportException`, `ToolingException`),
  caught in the commands, which return `Command::FAILURE` / `Command::INVALID`.
- Use precise PHPDoc types for PHPStan `max` (`list<string>`, `array<string, …>`, …).
- Everything is in English: code, identifiers, comments, messages, tests, docs and commits.

## Translations

- The texts written in merge requests and in the commits created in the target projects are localized
  (English and French shipped); the code, logs, console output and this repository's commits stay in English.
- Catalogs: `resources/translations/rustine+intl-icu.<locale>.yaml` (`symfony/translation`, ICU message format, nested
  YAML, `{name}` placeholders; requires `ext-intl`). Stick to simple arguments for now: no plural/select, and no
  apostrophe next to a brace (ICU quoting). In French, always write the typographic apostrophe `’`, never `'`. Every locale must have exactly the keys of English
  (`tests/I18n/CatalogsTest.php`). Add a language = add one file.
- Data (package names, versions, CVE ids, commands, branch and label names) is never translated.

## Tests

- Any new behavior is covered by a PHPUnit test in the matching `tests/` directory.
- `failOnWarning` and `failOnRisky` are enabled: a risky test or a warning fails the suite.
- No network calls and no real Trivy in tests: use `tests/fixtures/bin/fake-trivy` and the
  reports in `tests/fixtures/trivy/`.
- Integration tests (`tests/Apply/ComposerIntegrationTest.php`, `YarnBerryIntegrationTest.php`) run the real Composer /
  Yarn Berry 4+ and git, still without network: an inline `package` repository for Composer, a registry served by
  PHP's built-in server for Yarn. They are skipped when the tool is missing. Only Trivy stays faked.

## Git

- Conventional Commits (`feat:`, `fix:`, `test:`, `chore:`, …) with an explanatory body.
- Never commit `vendor/`, `.phpunit.cache/` or `.direnv/` (already in `.gitignore`).
