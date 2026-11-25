# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The merge request description lists the packages whose version changed in the lockfile, before and after, with the
  packages of the fixes in bold: a refresh also moves the dependencies that are not vulnerable, within their ranges.
  A branch kept because the fixes did not change keeps its description, so that it keeps describing the branch.
- `--yarn-bin` / `RUSTINE_YARN_BIN` and `--node-bin` / `RUSTINE_NODE_BIN` override the Yarn and Node commands, like
  `--composer-bin`. A Node given by path goes first on the `PATH` of the Yarn commands, so Yarn runs with it.

### Changed

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

- The consistency check of Composer (`composer validate`) is also run before the fixes, and only findings that it did not
  report then fail the final check: an unrelated finding that was already there no longer turns a whole group into a
  failure. The merge request shows them as already existing. It runs with `--no-plugins`.
- The commits are authored with the private commit email GitLab generates for the token owner
  (`<id>-<username>@users.noreply.<host>`) when it exposes no commit email, instead of a made-up address that the
  "commit author must be a GitLab user" push rule refuses.
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

### Documentation

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

[Unreleased]: https://github.com/umanit/rustine/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/umanit/rustine/releases/tag/v0.1.0
