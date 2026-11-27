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

### One merge request per ecosystem, optionally split by severity group

| Run                     | Branch                                    | Content                        |
|-------------------------|-------------------------------------------|--------------------------------|
| without `--group`       | `security/rustine-composer`               | Composer, every severity       |
|                         | `security/rustine-yarn`                   | Yarn, every severity           |
| `--group=critical-high` | `security/rustine-composer-critical-high` | Composer, CRITICAL and HIGH    |
|                         | `security/rustine-yarn-critical-high`     | Yarn, CRITICAL and HIGH        |
| `--group=others`        | `security/rustine-composer-others`        | Composer, MEDIUM, LOW, UNKNOWN |
|                         | `security/rustine-yarn-others`            | Yarn, MEDIUM, LOW, UNKNOWN     |

Without `--group`, everything goes in **one** merge request per ecosystem. With `--group` (repeatable), each group is
its own merge request. A package belongs to the group of the highest severity among *all* its vulnerable instances.
Trivy always runs with every severity. A typical split runs `critical-high` every day and `others` once a week, as two
schedules; a single run without `--group` is simpler when one merge request is enough.

The branches do not mix: switching from one way to the other opens new merge requests, and the ones of the old branches
stay open until someone closes them.

### Idempotency

The branch name is stable, so a run is a reconciliation of what the branch should contain:

| Situation                                   | What Rustine does                                                     |
|---------------------------------------------|-----------------------------------------------------------------------|
| No merge request, at least one fix          | Pushes the branch, opens the merge request                            |
| Open merge request, identical result        | Nothing (the texts and labels are refreshed if they are out of date)  |
| Open merge request, same fixes, no conflict | Nothing, even if the default branch moved on (no force-push)          |
| Open merge request, different fixes         | Force-pushes the branch, updates the description, leaves a note       |
| Open merge request, nothing fixable anymore | Closes it with a note, also when the scan finds nothing at all        |
| Closed by hand, same fixes, recently        | Nothing: the "no thanks" holds (`--closed-grace-days`, 30 by default) |
| Branch carries commits Rustine did not make | Leaves it untouched, posts a note **once**, asks for a manual action  |
| Limit of open merge requests reached        | Does not open a *new* merge request (existing ones are still updated) |

The branch is rebuilt from the default branch whenever it has to be pushed, so it carries exactly one Rustine commit.
When the analyzed branch (`--branch`) is not the project’s default branch (`$CI_DEFAULT_BRANCH`), its slug ends the name
of the Rustine branch (`security/rustine-yarn-feat-foo-bar`): each analyzed branch has its own branches and merge
requests, which target it, and none overwrites the ones of the default branch. Outside GitLab CI, where
`CI_DEFAULT_BRANCH` is not set, names never carry a slug.

`--branch` does not check anything out: the scan reads the checkout, so clone or check out the branch to analyze before
running `run`.

"Same fixes" means the fingerprint written in the description of the merge request (packages, versions, strategies) is
the one of the new run; a merge request with conflicts is rebuilt anyway. A merge request that someone closed by hand is
not opened again as long as the new run would propose the very same fixes (same fingerprint) and the closing is less
than `--closed-grace-days` days old (30 by default, `0` to always open a new one). Different fixes, or a later date,
open
a new merge request. The merge requests that Rustine closes itself (nothing fixable anymore) do not count: they carry
its note. To silence a CVE for good, put it in a `.trivyignore` file and pass `--trivy-arg=--ignorefile=.trivyignore`.

### Fix strategies

| Ecosystem    | Attempts, in order                                                                                                                                                                                                                                                                 |
|--------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Yarn Berry   | `yarn up -R <pkg>` (refresh within existing ranges) → direct range bump (`yarn up <pkg>@^x`) or `resolutions` (see below)                                                                                                                                                          |
| Yarn Classic | prune the `<pkg>` entries of `yarn.lock` and `yarn install` → direct range bump in `package.json` or `resolutions["**/<pkg>"]`                                                                                                                                                     |
| Composer     | `composer update <pkg> -W` → `composer require <pkg>:<constraint> -W` (the constraint keeps the style of the existing one: `6.4.*` → `6.5.*`, `~6.3.0` → `~6.5.0`, exact stays exact, `^` stays `^`; `--dev` for `require-dev`); on failure `composer why-not` is added to the log |

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
In both cases Flex must be allowed by `config.allow-plugins` (the global one for a global Flex, the one of
`composer.json` for `vendor/`): a non-interactive Composer blocks a plugin that is installed but not allowed, which
would silently lose the guarantee, so Rustine then stops with the same error.

### Safety guarantees

- Only `composer.json`, `composer.lock`, `package.json` and `yarn.lock` can change. Any other change abandons the fix,
  new untracked files and directories included: what a fix may create in the project directory (caches, reports) must be
  ignored by git (`.gitignore` or `.git/info/exclude`). Git reports an untracked directory as one entry, so files that
  appear inside a directory that already existed before the fix (a cache that Trivy or Yarn fills) are not a change.
- Only branches named `security/rustine-*` are ever written; the default branch is never pushed.
- Pushes use `--force-with-lease` on the commit seen at the beginning of the run.
- The token is never put in a URL, a command line or a log: it travels in an environment variable read by git
  (`http.extraHeader`), and is masked in every error message. It is also removed from the environment of Yarn,
  Composer and Trivy. That prevents accidental leaks, **not** a malicious program: a process running as the same
  user can read the environment of its parent (`/proc/<pid>/environ`). Isolation comes from what is allowed to run:
  see *What runs during a fix* below.
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

### Following a run

Composer, Yarn, git and Trivy print nothing of their own, so a run can look idle for a while. `run -v` writes one line
per step with the seconds elapsed since the start: fetching, starting from the default branch, each package and attempt,
the Trivy check, the consistency check, the push and the merge request.

```
security/rustine-composer
-------------------------
  [  0s] Looking for an open merge request
  [  1s] Fetching the default branch and the state of security/rustine-composer
  [  2s] Starting from the default branch
  [  2s] Checking the tools of the environment
  [  3s] Checking the project before the fixes
  [  4s] Fixing symfony/http-client v8.1.9 → 8.1.11 (1/3)
  [  4s]   attempt: lockfile-refresh
  [  9s] Skipping symfony/mime v8.1.7 (2/3): already fixed
  [ 11s] Verifying the fixes with Trivy
  [ 19s] Checking that the lockfile is consistent
  [ 21s] Committing the fixes
  [ 23s] Opening the merge request
```

### Dry run

`run --dry-run` reads a Trivy report and prints, for each group, the title, the severity, the commit message and the
description of the merge request that *would* be opened. Nothing is executed, so every fix is shown as *planned, not
verified*. Add `--report-file=preview.md` to keep the output.

### Configuration of `run`

Options take precedence over the configuration file (see below), the file over the variables, and the variables over
the defaults. Repeatable options (`--group`, `--label`, `--assignee`, `--reviewer`, `--notify`) read their variable as
values separated by commas (`RUSTINE_ASSIGNEE=alice,bob`), except `RUSTINE_TRIVY_ARG`, separated by spaces because Trivy
arguments contain commas (`--severity=HIGH,CRITICAL`). `RUSTINE_MAX_OPEN_MERGE_REQUESTS` and `RUSTINE_CLOSED_GRACE_DAYS`
must be non-negative integers. `RUSTINE_GROUP` and `RUSTINE_ECOSYSTEM` only apply to `run`; `RUSTINE_TRIVY_BIN` and
`RUSTINE_TRIVY_ARG` also apply to `scan` and `apply`.

#### Configuration file

The non-sensitive settings of a project can live in a YAML file committed with it, which is handy when the pipeline that
runs Rustine does not belong to the project (it cannot know its reviewers). Rustine reads `.rustine.yaml` at the root of
`--project` when it exists; `--config=<path>` or `RUSTINE_CONFIG=<path>` designate another file (then it must exist).
`run`, `scan` and `apply` accept it; `scan` and `apply` only use `include_dev`, `trivy_args` and (`apply`) `verify`.

A [JSON Schema](https://raw.githubusercontent.com/umanit/rustine/v0.6.0/schema/rustine.schema.json) describes the file:
with the modeline below (first line of the file), editors that rely on `yaml-language-server` (VS Code with the Red Hat
YAML extension, Neovim, Helix, PhpStorm…) offer completion, show the description of each key and flag unknown keys or
wrong types as you type.

The URL is pinned to a release tag, so the schema always matches the version you run (available from `v0.6.0`):
change the tag when you upgrade Rustine.

```yaml
# yaml-language-server: $schema=https://raw.githubusercontent.com/umanit/rustine/v0.6.2/schema/rustine.schema.json
assignee: [alice]
reviewer: [bob, carol]
notify: [my-group/security]
labels: [security, deps]
severity_label_format: 'severity::{severity}'
locale: fr
emoji: true                  # false = no emoji in the section headings (--no-emoji)
branch: main                 # branch to analyze (default: the default branch)
ecosystem: composer          # yarn or composer
group: [others]              # critical-high or others
max_open_merge_requests: 3
closed_grace_days: 30
include_dev: false
verify: final                # each, final or none
trivy_args: ['--ignorefile=.trivyignore']
git_author_name: Rustine
git_author_email: rustine@example.com
```

Every key matches the option of the same name (`labels` is `--label`, `trivy_args` is `--trivy-arg`; `emoji: false` is
`--no-emoji`); lists are real YAML lists, and an empty list overrides the variable. An unknown key or a value of the
wrong type stops the command: a typo never goes unnoticed. Nothing sensitive or tied to the machine is read from the
file: the token, the GitLab address and project, the binaries (`--*-bin`) and the remote stay in the options and the
environment of the job.

| Option                         | Variable                                              | Default / meaning                                                                                                                                                                                      |
|--------------------------------|-------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--group`                      | `RUSTINE_GROUP`                                       | `critical-high` or `others` (repeatable; `run`: default every group in one merge request per ecosystem; `apply`: exactly one; `RUSTINE_GROUP` takes them separated by commas)                          |
| `--ecosystem`                  | `RUSTINE_ECOSYSTEM`                                   | `yarn` or `composer` (default: both)                                                                                                                                                                   |
| `--project`                    |                                                       | Project directory (default `.`)                                                                                                                                                                        |
| `--gitlab-url`                 | `CI_API_V4_URL`                                       | GitLab API root, or the address of the instance (`/api/v4` is added when missing)                                                                                                                      |
| `--project-id`                 | `CI_PROJECT_ID`                                       | Project id or path                                                                                                                                                                                     |
| `--branch`, `--default-branch` | `RUSTINE_BRANCH`, then `CI_DEFAULT_BRANCH`            | Branch to analyze: the fixes start from it and the merge requests target it (default: the default branch of the project)                                                                               |
| *(no option, on purpose)*      | `RUSTINE_GITLAB_TOKEN` or `GITLAB_TOKEN`              | Access token                                                                                                                                                                                           |
| `--locale`                     | `RUSTINE_LOCALE`                                      | Language of the texts written in the project (default `en`)                                                                                                                                            |
| `--no-emoji`                   | `RUSTINE_NO_EMOJI`                                    | Do not prefix the section headings of the merge request with an emoji (on by default; `emoji: false` in the file)                                                                                      |
| `--label`                      | `RUSTINE_LABEL`                                       | Label of every merge request (repeatable, default `security`)                                                                                                                                          |
| `--severity-label-format`      | `RUSTINE_SEVERITY_LABEL_FORMAT`                       | Default `severity::{severity}` → `severity::high`                                                                                                                                                      |
| `--assignee`, `--reviewer`     | `RUSTINE_ASSIGNEE`, `RUSTINE_REVIEWER`                | Usernames, applied when a merge request is **created**                                                                                                                                                 |
| `--notify`                     | `RUSTINE_NOTIFY`                                      | Usernames or groups (repeatable) mentioned (`@name`) in the description, to notify them, without assigning the merge request                                                                           |
| `--max-open-merge-requests`    | `RUSTINE_MAX_OPEN_MERGE_REQUESTS`                     | Stops opening new merge requests above this number                                                                                                                                                     |
| `--closed-grace-days`          | `RUSTINE_CLOSED_GRACE_DAYS`                           | Does not open again a merge request closed by hand for the same fixes less than this many days ago (default `30`, `0` = always open)                                                                   |
| `--git-author-name/-email`     | `RUSTINE_GIT_AUTHOR_NAME`, `RUSTINE_GIT_AUTHOR_EMAIL` | Default: the token owner (commit email, else its GitLab private commit email `<id>-<user>@users.noreply.<host>`)                                                                                       |
| `--remote`                     | `RUSTINE_REMOTE`                                      | Git remote (default `origin`)                                                                                                                                                                          |
| `--report-file`                |                                                       | Markdown report: status and description of each group                                                                                                                                                  |
| `--report`                     |                                                       | Trivy report: written by a real run, read by `--dry-run`                                                                                                                                               |
| `--composer-bin`               | `RUSTINE_COMPOSER_BIN`                                | Command that runs Composer (default `composer`), e.g. `/usr/bin/php8.1 /usr/local/bin/composer2` to pick the PHP version; words separated by spaces, no quoting                                        |
| `--yarn-bin`                   | `RUSTINE_YARN_BIN`                                    | Command that runs Yarn (default `yarn`), e.g. `/opt/yarn/bin/yarn`; words separated by spaces, no quoting                                                                                              |
| `--node-bin`                   | `RUSTINE_NODE_BIN`                                    | Command that runs Node (default `node`); given by path, its directory goes first on the `PATH` of Yarn                                                                                                 |
| `--verify`                     | `RUSTINE_VERIFY`                                      | When Trivy confirms the fixes: `final` (default, once when every package is handled), `each` (after every attempt the lockfile accepts: slow, the most certain) or `none` (the lockfile alone decides) |
| `--include-dev`                | `RUSTINE_INCLUDE_DEV`                                 | Also scan the development dependencies (`require-dev`, `devDependencies`): see below. Off by default                                                                                                   |
| `--trivy-bin`, `--trivy-arg`   | `RUSTINE_TRIVY_BIN`, `RUSTINE_TRIVY_ARG`              | Trivy binary and extra arguments (repeatable), e.g. `--trivy-arg=--ignorefile=.trivyignore`                                                                                                            |

Labels are never translated (filters, boards and the open-merge-request limit depend on their names), nor are branch
names, CVE identifiers, versions or commands.

### Development dependencies

Trivy leaves the development dependencies out by default, and so does Rustine: a vulnerable `require-dev` package gets
no merge request. `--include-dev` (or `RUSTINE_INCLUDE_DEV=1`) is an opt-in that adds `--include-dev-deps` to every
Trivy run, the scan and the re-scans alike. Those packages are then fixed like any other (a `require-dev` constraint is
raised with `composer require --dev`) and listed in the same merge request. It is accepted by `scan`, `run` and `apply`:
give it to `apply` as well when its report was made with it, otherwise the re-scans do not see the development
dependencies.

Observed with Trivy 0.72: it marks the development packages of a `composer.lock` (`Dev: true`), while the results on a
Yarn project were the same with and without the option.

### Exit codes

| Code | Meaning                                                                                                                 |
|------|-------------------------------------------------------------------------------------------------------------------------|
| `0`  | Done, nothing left for a human                                                                                          |
| `1`  | A group failed (git, GitLab, lockfile consistency…) or the command was misused                                          |
| `2`  | Everything that could be fixed was; some packages need a manual action, or a group was skipped (limit, foreign commits) |

In GitLab CI, `allow_failure: { exit_codes: [2] }` turns code `2` into an orange job instead of a red one.

### Creating the token

Rustine needs a token because `CI_JOB_TOKEN` cannot push branches nor open merge requests.

1. Create an **access token** with the **Developer** role and the scopes `api` and `write_repository`: either one
   **project access token** per project, or one **group access token** for all of them (a bot user's token works too).
2. Store it in a **masked and protected** CI/CD variable named `RUSTINE_GITLAB_TOKEN`, at the level of the token.
3. Merge requests and notes are attributed to the token owner. Commits need an identity too: it is read from the token
   owner (`GET /user`), or set with `--git-author-name` / `--git-author-email`.

Protected variables are only exposed to protected refs: run the schedule on the default branch.

**Group or project token?** A group token is convenient (one variable, one expiry date), but it has the `api` scope on
**every** project of the group, and anyone who can change the `.gitlab-ci.yml` of a protected branch of *any* of those
projects can have a job print it. A project token limits the damage of a leak to one project, at the price of one token
to create and renew per project. Prefer project tokens for the sensitive projects, and give every token an expiry date.

### What runs during a fix

Rustine runs the tools of the project, and so the code they load. Know what that is before giving it a token:

| Tool     | Code of the project that runs                                                                                                                                                                                                           |
|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Yarn     | Lifecycle scripts are disabled, but the plugins listed in `.yarnrc.yml` are loaded (they are committed in `.yarn/plugins/`)                                                                                                             |
| Composer | Scripts and plugins are disabled. For a project that sets `extra.symfony.require`, plugins are enabled to let Symfony Flex work: a Flex installed globally is the only plugin loaded, a `vendor/` also loads the plugins of the project |
| Trivy    | None: it only reads the lockfiles                                                                                                                                                                                                       |

The code comes from the default branch (the job runs there), so it is code that has already been merged, and the
plugins it can load are the ones the project already trusts in its own CI. The difference with the other jobs
is the token: keep the schedule on the default branch, and a protected variable.

### Example job

Rustine does not ship a CI template; a minimal job looks like this, to adapt to your own CI. Preparing the tools is the
job of the CI, not of Rustine: here `mise` installs the versions of the project before Rustine starts (an image that
already ships them works as well).

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
- **A Yarn project whose `package.json` points to a local source** (`file:`, `link:`, `portal:`) that does not exist is
  refused with an explicit error: Yarn cannot resolve it. The exception is a path in the Composer vendor directory, as
  Symfony UX declares (`file:vendor/symfony/ux-…/assets`): when the package is in `composer.lock`, Rustine fetches that
  package alone, at its locked version and without its dependencies (Composer with plugins and scripts disabled, in a
  throwaway project), only for the Yarn pass, then removes it. No `composer install` is needed before Rustine for
  that. Composer must then be on the `PATH`, and patches applied by a plugin (`cweagans/composer-patches`) are not.
- **A Composer package that `package.json` points into is not refreshed in `yarn.lock`.** When a fix moves such a
  package (`symfony/ux-live-component` for `file:vendor/symfony/ux-live-component/assets`), Yarn records a new version
  and hash for it, so the lockfile goes stale and a frozen install (`yarn install --immutable`) fails in CI. Rustine
  only fixes the vulnerable packages: it flags it in the "needs manual attention" section (exit code `2`), and the
  merge request needs a `yarn install` and a commit of `yarn.lock` before it is merged.
- A package declared with a git/URL/alias/local source is reported, never fixed.
- Major version bumps are reported, never applied.
- A merge request closed by hand is only left closed for `--closed-grace-days` days (30 by default), and only while the
  fixes are the same. To silence a CVE for good, use a `.trivyignore` file.
- **The scan reads the checkout of the pipeline, the fixes start from the tip of the default branch** (fetched at the
  moment each merge request is built). If the default branch moved between the two, the plan can lag behind by a
  commit: what is already fixed is detected and reported as such, and the next run catches up. Run the job on the
  default branch pipeline (`rules: if $CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH`, or a schedule) to keep the gap to a few
  seconds.

## Development

```bash
composer install
make full_static_analyze   # PHP_CodeSniffer + Rector + PHPStan (max level) + PHPUnit; must pass after any change
```

Tests use real temporary git repositories and a local bare remote, `MockHttpClient` for GitLab and a fake Trivy: no
network. The integration tests run the real Composer, Yarn (Berry and Classic) and git, and are skipped when a tool is
missing. See [`AGENTS.md`](AGENTS.md) for the conventions and the architecture.

## License

Rustine is released under the [MIT License](LICENSE).
