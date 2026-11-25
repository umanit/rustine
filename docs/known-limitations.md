# Known limitations

- **Monorepos** are out of scope for now: one `composer.json`/`package.json` at the project root. Lockfiles found
  elsewhere by Trivy (`tools/*/composer.lock`, a sub-project, `node_modules/`…) are ignored and listed in the job log.
- **Yarn Berry in PnP mode or with a committed cache** (`.pnp.cjs`, `.yarn/cache`) is refused rather than half-handled.
- A Berry `resolutions` key written with a descriptor (`pkg@npm:^1`) is not recognized as equivalent to `pkg`.
- A `resolutions` entry is refused when the package is installed in several major versions (it would override all of
  them).
- **A Yarn project whose `package.json` points to a local source** (`file:`, `link:`, `portal:`) that does not exist is
  refused with an explicit error: Yarn cannot resolve it. It is typical of Symfony UX, which declares
  `file:vendor/symfony/ux-…/assets`: run `composer install --no-scripts` before Rustine, as it installs nothing.
- A package declared with a git/URL/alias/local source is reported, never fixed.
- Major version bumps are reported, never applied.
- A merge request closed by hand is only left closed for `--closed-grace-days` days (30 by default), and only while the
  fixes are the same. To silence a CVE for good, use a `.trivyignore` file.
- **The scan reads the checkout of the pipeline, the fixes start from the tip of the default branch** (fetched at the
  moment each merge request is built). If the default branch moved between the two, the plan can lag behind by a
  commit: what is already fixed is detected and reported as such, and the next run catches up. Run the job on the
  default branch pipeline (`rules: if $CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH`, or a schedule) to keep the gap to a few
  seconds.
