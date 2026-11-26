# How it works

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

## One merge request per ecosystem, optionally split by severity group

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

## Idempotency

The branch name is stable, so a run is a reconciliation of what the branch should contain:

| Situation                                   | What Rustine does                                                     |
|---------------------------------------------|-----------------------------------------------------------------------|
| No merge request, at least one fix          | Pushes the branch, opens the merge request                            |
| Open merge request, identical result        | Nothing (the texts and labels are refreshed if they are out of date)  |
| Open merge request, same fixes, no conflict | Nothing, even if the default branch moved on (no force-push)          |
| Open merge request, different fixes         | Force-pushes the branch, updates the description, leaves a note       |
| Open merge request, nothing fixable anymore | Closes it with a note                                                 |
| Closed by hand, same fixes, recently        | Nothing: the "no thanks" holds (`--closed-grace-days`, 30 by default) |
| Branch carries commits Rustine did not make | Leaves it untouched, posts a note **once**, asks for a manual action  |
| Limit of open merge requests reached        | Does not open a *new* merge request (existing ones are still updated) |

The branch is rebuilt from the default branch whenever it has to be pushed, so it carries exactly one Rustine commit.
"Same fixes" means the fingerprint written in the description of the merge request (packages, versions, strategies) is
the one of the new run; a merge request with conflicts is rebuilt anyway. A merge request that someone closed by hand is
not opened again as long as the new run would propose the very same fixes (same fingerprint) and the closing is less
than `--closed-grace-days` days old (30 by default, `0` to always open a new one). Different fixes, or a later date,
open
a new merge request. The merge requests that Rustine closes itself (nothing fixable anymore) do not count: they carry
its note. To silence a CVE for good, put it in a `.trivyignore` file and pass `--trivy-arg=--ignorefile=.trivyignore`.

## Fix strategies

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

## Safety guarantees

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
