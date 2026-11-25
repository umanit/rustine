<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/img/rustine-logo-dark.svg">
    <img src="docs/img/rustine-logo.svg" alt="Rustine" width="320">
  </picture>
</p>

<p align="center">
  <a href="https://github.com/umanit/rustine/actions/workflows/ci.yml"><img src="https://github.com/umanit/rustine/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI"></a>
</p>

**Rustine** opens GitLab merge requests that fix **only** the Composer and Yarn packages affected by a CVE reported by
[Trivy](https://trivy.dev). No comfort updates, no noise: a package gets a merge request because it is vulnerable, and
only the vulnerable packages are targeted (no major bump, no dependency that is not affected). The first attempt, a
refresh of the lockfile, resolves the highest version each declared range allows, so other dependencies may move along:
the description of the merge request lists every package the lockfile changes.

> **Status:** pre-release (`0.x`): the CLI is complete and ready for pilot projects; expect adjustments.

## How it works

On every scheduled run, for the ecosystem(s) and severity group you ask for:

1. **Scan** — Trivy runs on the project (`--list-all-pkgs`, to tell direct from transitive dependencies). The installed
   dependencies (`**/vendor`, `**/node_modules`) are skipped and telemetry is disabled.
2. **Plan** — vulnerable packages are grouped per ecosystem and severity group; for each one Rustine picks the smallest
   fixed version, in the same major version when possible (the same minor version for `0.x`, where a new minor is a
   breaking change).
3. **Apply** — package by package, strategies are tried in order. After each attempt the lockfile is read back: the
   change is kept only if the package is no longer locked at a vulnerable version, otherwise the files are restored and
   the next strategy is tried. A package that cannot be fixed never blocks the others. Trivy runs once, when every
   package has been handled, to confirm the result: a fix whose CVEs it still reports is flagged for a manual check.
4. **Publish** — a stable branch is force-pushed and a merge request is opened or updated; its description lists the
   CVEs, the dependency chain, the strategy used and the verification.

A merge request exists **if and only if at least one fix was applied**. Everything that cannot be fixed automatically
(major version bump, no fixed version published, git/URL/alias dependency, every attempt failed) is reported — never
silently dropped — in the "needs manual attention" section of the merge request, in the job log, in the report file
and through the exit code.

### One merge request per ecosystem × severity group

| Branch                                    | Content                        |
|-------------------------------------------|--------------------------------|
| `security/rustine-composer-critical-high` | Composer, CRITICAL and HIGH    |
| `security/rustine-composer-others`        | Composer, MEDIUM, LOW, UNKNOWN |
| `security/rustine-yarn-critical-high`     | Yarn, CRITICAL and HIGH        |
| `security/rustine-yarn-others`            | Yarn, MEDIUM, LOW, UNKNOWN     |

A package belongs to the group of the highest severity among *all* its vulnerable instances. Trivy always runs with
every severity; you choose the group at run time (`--group`, exactly one). A typical setup runs `critical-high` every
day and `others` once a week, as two schedules.

### Idempotency

The branch name is stable, so a run is a reconciliation of what the branch should contain:

| Situation                                   | What Rustine does                                                     |
|---------------------------------------------|-----------------------------------------------------------------------|
| No merge request, at least one fix          | Pushes the branch, opens the merge request                            |
| Open merge request, identical result        | Nothing (the texts and labels are refreshed if they are out of date)  |
| Open merge request, same fixes, no conflict | Nothing, even if the default branch moved on (no force-push)          |
| Open merge request, different fixes         | Force-pushes the branch, updates the description, leaves a note       |
| Open merge request, nothing fixable anymore | Closes it with a note                                                 |
| Branch carries commits Rustine did not make | Leaves it untouched, posts a note **once**, asks for a manual action  |
| Limit of open merge requests reached        | Does not open a *new* merge request (existing ones are still updated) |

The branch is rebuilt from the default branch whenever it has to be pushed, so it carries exactly one Rustine commit.
"Same fixes" means the fingerprint written in the description of the merge request (packages, versions, strategies) is
the one of the new run; a merge request with conflicts is rebuilt anyway. A merge request that was closed by hand is
opened again at the next run.

### Fix strategies

| Ecosystem    | Attempts, in order                                                                                                                         |
|--------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| Yarn Berry   | `yarn up -R <pkg>` (refresh within existing ranges) → direct range bump (`yarn up <pkg>@^x`) or `resolutions` (see below)                  |
| Yarn Classic | prune the `<pkg>` entries of `yarn.lock` and `yarn install` → direct range bump in `package.json` or `resolutions["**/<pkg>"]`             |
| Composer     | `composer update <pkg> -W` → `composer require <pkg>:^x -W` (`--dev` for `require-dev`); on failure `composer why-not` is added to the log |

The description of the merge request lists what actually changed in the lockfile (versions before and after), the
packages of the fixes in bold and the ones that moved along as is. With Yarn Berry 4 or later, every attempt runs with
`--mode=update-lockfile`: Yarn resolves and fetches what the lockfile
lacks but skips the link step, so `node_modules` is not rebuilt after each attempt of each package (Trivy only reads
`yarn.lock`). The final `yarn install --immutable` is a full install and brings `node_modules` in line with the result.
Older Berry versions run the plain commands.

`resolutions` are technical debt, and are flagged as such in the description. A final `yarn install --immutable` /
`--frozen-lockfile` / `composer validate` must pass before anything is pushed.

Composer runs with `--no-install --no-scripts --no-plugins`: nothing is installed, and no script or plugin of the
project runs (a plugin is arbitrary code, and Rustine holds a powerful token). The one exception is a project that sets
`extra.symfony.require`, which only Symfony Flex enforces while Composer resolves: without Flex, an update moves the
`symfony/*` components that the project does not require itself to another minor (`symfony/string` 6.4 → 7.4 in a 6.4
project). Plugins are then enabled and Rustine checks that Flex is installed, otherwise it stops with an explicit error
instead of producing that merge request. Install Flex **globally** in the environment of the job:

```bash
composer global config allow-plugins.symfony/flex true && composer global require symfony/flex --no-scripts
```

Only Flex is then loaded, and no plugin of the project. A `vendor/symfony/flex` (`composer install --no-scripts`) works
too, but loads every plugin of the project: they run with the same privileges as Rustine, which can read its
environment, so only do it for a project whose plugins you trust as much as its CI.

### Safety guarantees

- Only `composer.json`, `composer.lock`, `package.json` and `yarn.lock` can change. Any other change abandons the fix,
  untracked files included: anything a fix may create in the project directory (caches, reports) must be ignored by
  git (`.gitignore` or `.git/info/exclude`).
- Only branches named `security/rustine-*` are ever written; the default branch is never pushed.
- Pushes use `--force-with-lease` on the commit seen at the beginning of the run.
- The token is never put in a URL, a command line or a log: it travels in an environment variable read by git
  (`http.extraHeader`), and is masked in every error message.
- Dependencies declared with a git/URL/alias/local source (or a `dev-*` branch for Composer) are never replaced by a
  version from the registry: they are reported for a manual fix.
- Yarn scripts are disabled during the fixes.

## Requirements

| Needed                    | Notes                                                                      |
|---------------------------|----------------------------------------------------------------------------|
| PHP ≥ 8.4 with `ext-intl` | To run Rustine itself                                                      |
| `git`                     | The project must be a git repository with a configured remote              |
| `trivy`                   | Version compatible with `--list-all-pkgs` (developed with 0.72)            |
| `node`, `yarn`            | Yarn projects: on the `PATH`, or given by `--node-bin` / `--yarn-bin`      |
| `composer`, PHP           | Composer projects: on the `PATH`, or the command given by `--composer-bin` |

Rustine runs **inside the project's own CI job**, so that `composer update` and `yarn install` use the same PHP, Node
and Yarn versions as the project.

### Tools

Rustine **installs nothing**: the environment that runs it must already provide the tools of the project, in the
versions the project expects (CI image, `mise install`, Volta, Corepack…, before Rustine starts). Commands run as they
are, with the tools found on the `PATH` unless `--yarn-bin`, `--node-bin` or `--composer-bin` point elsewhere.

Before fixing a group, Rustine checks the environment and stops with an explicit error when:

- `node` or `yarn` (Yarn projects), or Composer (Composer projects), cannot be run;
- the Yarn of the environment does not match `yarn.lock`: a Berry lockfile (`__metadata`) needs Yarn 2+, a Classic
  lockfile (`# yarn lockfile v1`) needs Yarn 1. The fix ladder (Berry or Classic) is chosen from the lockfile.

The versions found are written in the merge request description.

## Installation

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

## Usage

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

`apply` modifies the working tree of the project (no commit, no push): only try it on a throwaway copy.

### Dry run

`run --dry-run` reads a Trivy report and prints, for each group, the title, the severity, the commit message and the
description of the merge request that *would* be opened. Nothing is executed, so every fix is shown as *planned, not
verified*. Add `--report-file=preview.md` to keep the output.

### Configuration of `run`

Options take precedence over the variables GitLab CI provides.

| Option                       | Variable                                 | Default / meaning                                                                                                                                                                                      |
|------------------------------|------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--group`                    |                                          | **Required**: `critical-high` or `others` (exactly one)                                                                                                                                                |
| `--ecosystem`                |                                          | `yarn` or `composer` (default: both)                                                                                                                                                                   |
| `--project`                  |                                          | Project directory (default `.`)                                                                                                                                                                        |
| `--gitlab-url`               | `CI_API_V4_URL`                          | GitLab API root                                                                                                                                                                                        |
| `--project-id`               | `CI_PROJECT_ID`                          | Project id or path                                                                                                                                                                                     |
| `--default-branch`           | `CI_DEFAULT_BRANCH`                      | Branch the fixes start from and the merge requests target                                                                                                                                              |
| *(no option, on purpose)*    | `RUSTINE_GITLAB_TOKEN` or `GITLAB_TOKEN` | Access token                                                                                                                                                                                           |
| `--locale`                   | `RUSTINE_LOCALE`                         | Language of the texts written in the project (default `en`)                                                                                                                                            |
| `--label`                    |                                          | Label of every merge request (repeatable, default `security`)                                                                                                                                          |
| `--severity-label-format`    |                                          | Default `severity::{severity}` → `severity::high`                                                                                                                                                      |
| `--assignee`, `--reviewer`   |                                          | Usernames, applied when a merge request is **created**                                                                                                                                                 |
| `--max-open-merge-requests`  |                                          | Stops opening new merge requests above this number                                                                                                                                                     |
| `--git-author-name/-email`   |                                          | Default: the token owner (commit email, else its GitLab private commit email `<id>-<user>@users.noreply.<host>`)                                                                                                                                      |
| `--remote`                   |                                          | Git remote (default `origin`)                                                                                                                                                                          |
| `--report-file`              |                                          | Markdown report: status and description of each group                                                                                                                                                  |
| `--report`                   |                                          | Trivy report: written by a real run, read by `--dry-run`                                                                                                                                               |
| `--composer-bin`             | `RUSTINE_COMPOSER_BIN`                   | Command that runs Composer (default `composer`), e.g. `/usr/bin/php8.1 /usr/local/bin/composer2` to pick the PHP version; words separated by spaces, no quoting                                        |
| `--yarn-bin`                 | `RUSTINE_YARN_BIN`                       | Command that runs Yarn (default `yarn`), e.g. `/opt/yarn/bin/yarn`; words separated by spaces, no quoting                                                                                              |
| `--node-bin`                 | `RUSTINE_NODE_BIN`                       | Command that runs Node (default `node`); given by path, its directory goes first on the `PATH` of Yarn                                                                                                 |
| `--verify`                   | `RUSTINE_VERIFY`                         | When Trivy confirms the fixes: `final` (default, once when every package is handled), `each` (after every attempt the lockfile accepts: slow, the most certain) or `none` (the lockfile alone decides) |
| `--trivy-bin`, `--trivy-arg` |                                          | Trivy binary and extra arguments (repeatable), e.g. `--trivy-arg=--ignorefile=.trivyignore`                                                                                                            |

Labels are never translated (filters, boards and the open-merge-request limit depend on their names), nor are branch
names, CVE identifiers, versions or commands.

### Exit codes

| Code | Meaning                                                                                                                 |
|------|-------------------------------------------------------------------------------------------------------------------------|
| `0`  | Done, nothing left for a human                                                                                          |
| `1`  | A group failed (git, GitLab, lockfile consistency…) or the command was misused                                          |
| `2`  | Everything that could be fixed was; some packages need a manual action, or a group was skipped (limit, foreign commits) |

In GitLab CI, `allow_failure: { exit_codes: [2] }` turns code `2` into an orange job instead of a red one.

### Creating the token

Rustine needs a token because `CI_JOB_TOKEN` cannot push branches nor open merge requests.

1. Create a **group access token** (or a bot user's token) with the **Developer** role and the scopes `api` and
   `write_repository`.
2. Store it in a **masked and protected** CI/CD variable named `RUSTINE_GITLAB_TOKEN`, at group level.
3. Merge requests and notes are attributed to the token owner. Commits need an identity too: it is read from the token
   owner (`GET /user`), or set with `--git-author-name` / `--git-author-email`.

Protected variables are only exposed to protected refs: run the schedule on the default branch.

### Example job

A shared template is planned; in the meantime, a minimal job looks like this. Preparing the tools is the job of the CI,
not of Rustine: here `mise` installs the versions of the project before Rustine starts (an image that already ships
them works as well).

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

## Languages

The texts Rustine writes **in the target projects** (merge request title and description, notes, commit message) are
localized; English and French are shipped. The code, logs, console output, errors and this repository's own commits
stay in English. Technical logs of the strategies (in a collapsed block) are in English too.

To add a language, add one file: `resources/translations/rustine+intl-icu.<locale>.yaml`, a copy of the English one,
translated. The format is ICU (`{name}` placeholders; no plural/select for now; mind the apostrophe quoting rules,
and in French use the typographic `’`). A test enforces that every language has exactly the keys and placeholders of
English.

## Known limitations

- **Monorepos** are out of scope for now: one `composer.json`/`package.json` at the project root. Lockfiles found
  elsewhere by Trivy (`tools/*/composer.lock`, a sub-project, `node_modules/`…) are ignored and listed in the job log.
- **Yarn Berry in PnP mode or with a committed cache** (`.pnp.cjs`, `.yarn/cache`) is refused rather than half-handled.
- A Berry `resolutions` key written with a descriptor (`pkg@npm:^1`) is not recognized as equivalent to `pkg`.
- A `resolutions` entry is refused when the package is installed in several major versions (it would override all of
  them).
- A package declared with a git/URL/alias/local source is reported, never fixed.
- Major version bumps are reported, never applied.
- Closing a merge request by hand does not stop Rustine from opening it again.

## Development

```bash
composer install
make full_static_analyze   # PHPStan (max level) + PHPUnit; must pass after any change
```

Tests use real temporary git repositories and a local bare remote, `MockHttpClient` for GitLab and a fake Trivy: no
network, no real Yarn or Composer run. See [`AGENTS.md`](AGENTS.md) for the conventions and the architecture.

## License

Rustine is released under the [MIT License](LICENSE).
