# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-11-25

### Added

- `run -v` prints one line per step with the seconds elapsed since the start (fetching, starting from the default
  branch, each package and attempt, the Trivy check, the consistency check, the push and the merge request), instead of
  staying silent while Composer, Yarn, git and Trivy work.
- `--notify=<user or group>` on `run` (repeatable, with or without the leading `@`) mentions people in the description of
  the merge requests (`cc @alice @team/sec`, `Pour information : @alice` in French), so that GitLab notifies them without
  assigning the merge request to them.
- More options of `run` can be set by environment variable (the option still wins, then the variable, then the
  default): `RUSTINE_GROUP`, `RUSTINE_ECOSYSTEM`, `RUSTINE_REMOTE`, `RUSTINE_LABEL`, `RUSTINE_SEVERITY_LABEL_FORMAT`,
  `RUSTINE_ASSIGNEE`, `RUSTINE_REVIEWER`, `RUSTINE_NOTIFY`, `RUSTINE_MAX_OPEN_MERGE_REQUESTS`,
  `RUSTINE_CLOSED_GRACE_DAYS`, `RUSTINE_GIT_AUTHOR_NAME` and `RUSTINE_GIT_AUTHOR_EMAIL`. Lists are separated by commas;
  the two numbers must be non-negative integers. `RUSTINE_TRIVY_BIN` and `RUSTINE_TRIVY_ARG` (separated by spaces, as
  Trivy arguments contain commas) are also read by `scan` and `apply`.

### Changed

- `run` no longer requires `--group`: without it, every severity group goes in a single merge request per ecosystem, on
  the branch `security/rustine-<ecosystem>` (no group suffix; title and commit without the group). `--group` can be
  given several times, each group then being its own merge request. `plan` follows the same rule. `apply` still takes
  exactly one group, as it works on the working tree.

### Fixed

- `--gitlab-url` accepts the address of the instance alone (`https://gitlab.example.com`, with or without a trailing
  slash): `/api/v4` is added when it is missing. Before, the request went to a web page and failed with "Response body is
  empty".

## [0.2.0] - 2026-11-25

### Added

- `--closed-grace-days` (default 30, `0` to disable): a merge request that someone closed by hand is not opened again
  while the new run would propose the very same fixes (same fingerprint) and the closing is more recent than that. The
  merge requests that Rustine closes itself (nothing fixable anymore) carry a marker in their note and do not count.
  Different fixes open a new merge request. The run reports it as skipped, without a manual action.
- `--verify` (`RUSTINE_VERIFY`): when Trivy re-checks the fixes. `final` (default) runs it once, after every package has
  been handled; `each` runs it after each fix; `none` relies on the lockfile only.
- `--include-dev` (or `RUSTINE_INCLUDE_DEV=1`) on `scan`, `run` and `apply` also scans the development dependencies, which
  stay out by default. The same flag is given to the scan and to the re-scans.
- The merge request description lists the packages whose version changed in the lockfile, before and after, with the
  packages of the fixes in bold: a refresh also moves the dependencies that are not vulnerable, within their ranges.
  A branch kept because the fixes did not change keeps its description, so that it keeps describing the branch.
- `--yarn-bin` / `RUSTINE_YARN_BIN` and `--node-bin` / `RUSTINE_NODE_BIN` override the Yarn and Node commands, like
  `--composer-bin`. A Node given by path goes first on the `PATH` of the Yarn commands, so Yarn runs with it.

### Changed

- Every attempt is checked by reading the lockfile back, and Trivy runs **once** at the end to confirm the result
  (`--verify=final`), instead of a full scan after each attempt of each package. A fix whose CVEs Trivy still reports is
  flagged for a manual check.
- `composer require` raises a constraint in the style the project wrote it instead of replacing it with a caret:
  `6.4.*` becomes `6.5.*`, `~6.3.0` becomes `~6.5.0`, an exact version stays exact, `^` stays `^`. A constraint it cannot
  read (`||`, ranges) still becomes `^x.y.z`.
- `package.json` is edited in place: the rest of the file (inline arrays such as `files` or `browserslist`, spacing, key
  order, line endings) stays byte for byte as it was, so the diff only shows the changed range or `resolutions` entry.
  A layout it does not handle (an object on a single line) falls back to re-encoding the JSON.
- On a shallow clone, the branch of a merge request is fetched with `--shallow-exclude=<default branch>`: only its own
  commits are downloaded to check who made them, instead of the whole history. `--unshallow` remains the fallback for a
  server that cannot do it.
- The re-scans of `run` skip the Trivy database update (`--skip-db-update`): the scan that starts the run has just
  refreshed it. `apply` does not, as its report may come from elsewhere.
- With Yarn Berry 4+, the attempts run with `--mode=update-lockfile` (no link step, `node_modules` untouched until the
  final check), which makes a run much shorter on a project with many vulnerable packages. Checked on Yarn 4.14.1: the
  lockfile is identical to the one of a plain `yarn up -R`. Older Berry versions keep the plain commands.
- Trivy runs with `--skip-dirs **/vendor,**/node_modules` (Rustine only handles the lockfiles of the project root, so
  the scans and re-scans no longer read the installed dependencies) and `--disable-telemetry`. The `--trivy-arg`
  values come after, and Trivy accumulates repeated `--skip-dirs`.
- **Breaking:** Rustine no longer installs any tool. Yarn and Composer commands run as they are, with the tools of the
  environment, instead of through `mise exec` (which installed the pinned Node and Yarn versions on the fly). The CI job
  must provide the tools before Rustine starts.
- Before fixing a group, Rustine checks that `node` and `yarn` (or Composer) can be run, and that the Yarn of the
  environment matches `yarn.lock` (Berry or Classic); otherwise it stops with an explicit error. The Yarn fix ladder is
  chosen from the format of `yarn.lock`.
- The merge request description gives the versions of the tools of the environment.

### Removed

- Reading the tool versions pinned by `mise.toml` or by the `volta` key of `package.json`: a Yarn project no longer
  needs to pin its tools, and `mise` is no longer a requirement.

### Fixed

- The GitLab credentials and other secrets that a tool prints (registry URLs with `user:password@`, `Bearer` and `Basic`
  values, npm `_authToken` lines) are masked in the end of tool output that is quoted in a merge request.
- A Yarn Classic `yarn.lock` with CRLF line endings is edited correctly (it was split on LF blank lines, so no entry was
  ever pruned) and keeps its line endings.
- The git authentication header is added after the `GIT_CONFIG_*` pairs that the runner may already set (proxy, CA bundle,
  `safe.directory`…) instead of overwriting them with `GIT_CONFIG_COUNT=1`.
- A Yarn project whose `package.json` points to a local source that does not exist (`file:`, `link:`, `portal:`, e.g.
  `file:vendor/symfony/ux-live-component/assets` before `composer install`) is refused up front with an explicit error.
  Before, every package failed on a truncated Yarn error, one after the other.
- Files that appear inside a directory that was already untracked before a fix (a cache that Trivy or Yarn fills) no longer
  abandon it as unexpected changes: `git status` reports an untracked directory as one entry. A new untracked file or
  directory still does.
- The commits are authored with the private commit email GitLab generates for the token owner
  (`<id>-<username>@users.noreply.<host>`) when it exposes no commit email, instead of a made-up address that the
  "commit author must be a GitLab user" push rule refuses.
- The consistency check of Composer (`composer validate`) is also run before the fixes, and only findings that it did not
  report then fail the final check: an unrelated finding that was already there no longer turns a whole group into a
  failure. The merge request shows them as already existing. It runs with `--no-plugins`.
- A project that sets `extra.symfony.require` no longer gets its `symfony/*` components moved to another minor by a
  Composer refresh (`symfony/string` 6.4 → 7.4 in a 6.4 project): only Symfony Flex enforces that setting, and it was
  disabled with the plugins. Composer still runs with `--no-plugins` everywhere else; for such a project the plugins are
  enabled and Rustine stops with an explicit error unless Flex is installed, preferably globally
  (`composer global require symfony/flex --no-scripts`) so that no plugin of the project is loaded, otherwise in
  `vendor/`. Scripts stay disabled and nothing is installed.
- The branch of an open merge request is no longer force-pushed (with a "rebuilt" note and a new pipeline) at every
  run once the default branch has moved: when the fingerprint in its description is the one of the new run and it has no
  conflict, it is left as it is.
- Only the lockfiles at the root of the project are handled: a CVE Trivy reports in `tools/*/composer.lock`, a
  sub-project or `node_modules/` is no longer planned as a dependency of the root project.
- A fix is no longer reported as failed because another major version of the same package carries the same CVE.
- Below 1.0, a new minor version is treated as a major bump (`0.3.x` → `0.4.x` is a breaking change in semver).
- The GitLab token (`RUSTINE_GITLAB_TOKEN`, `GITLAB_TOKEN`) is removed from the environment of Yarn, Composer, git and
  Trivy.
- `run` fails when the `--report-file` cannot be written, instead of ignoring it.

### Tests

- The real Composer, Yarn Berry 4 and Yarn Classic 1 run against local packages (an inline `package` repository, a
  registry served by PHP's built-in server) in a real git repository, without network; only Trivy stays faked. They are
  skipped when the tool is missing, and the CI runs them.

### Documentation

- A merge request closed by hand, the gap between the scanned checkout and the tip of the default branch, and the
  `.trivyignore` file to silence a CVE for good are described in the README.
- The token: removing it from the environment of the child processes prevents leaks, not a malicious program (a process
  can read the environment of its parent). The README lists what code runs during a fix, and compares a project token with
  a group token.
- The example job hides the caches kept in the project directory from git, so that a fix does not take their new files
  for unexpected changes.

## [0.1.0] - 2026-11-24

First release.

### Added

- `scan`: runs Trivy on a project and writes its JSON report (with `--list-all-pkgs`, to tell direct from transitive
  dependencies).
- `plan`: groups the vulnerable Composer and Yarn packages per ecosystem and severity group (`critical-high` or
  `others`) and picks the smallest fixed version, in the same major version when possible.
- `apply`: fixes the packages one by one in the working tree (no commit, no push) and re-scans with Trivy to check that
  the CVEs are gone.
  - Yarn Berry (`yarn up -R`, then a direct range or a `resolutions` entry) and Yarn Classic (lockfile pruning, then a
    direct range or a `resolutions` entry).
  - Composer (`composer update`, then `composer require`; `composer why-not` output when blocked).
  - Guard-rails: only `composer.json`/`composer.lock` and `package.json`/`yarn.lock` may change; git, URL and alias
    sources and major-version bumps are reported for manual action, never applied.
- `run`: the CI entry point. Scans, plans, applies, commits, pushes and opens, updates or closes one GitLab merge
  request per ecosystem and severity group, on a stable `security/rustine-*` branch. Exit code 2 means a manual action
  is needed; `--report-file` writes a Markdown report; `--dry-run` previews the texts from a Trivy report.
- Tool versions pinned by `mise.toml`, or by the `volta` key of `package.json` (`mise.toml` wins), applied through
  `mise exec`.
- Merge request titles, descriptions, notes and commit messages in English and French (`--locale` or `RUSTINE_LOCALE`).
- A single executable PHAR, built and published with its SHA-256 by the GitHub release workflow.

[Unreleased]: https://github.com/umanit/rustine/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/umanit/rustine/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/umanit/rustine/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/umanit/rustine/releases/tag/v0.1.0
