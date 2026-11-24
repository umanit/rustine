# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
