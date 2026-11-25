# Requirements

| Needed                    | Notes                                                                      |
|---------------------------|----------------------------------------------------------------------------|
| PHP ≥ 8.4 with `ext-intl` | To run Rustine itself                                                      |
| `git`                     | The project must be a git repository with a configured remote              |
| `trivy`                   | Version compatible with `--list-all-pkgs` (developed with 0.72)            |
| `node`, `yarn`            | Yarn projects: on the `PATH`, or given by `--node-bin` / `--yarn-bin`      |
| `composer`, PHP           | Composer projects: on the `PATH`, or the command given by `--composer-bin` |

Rustine runs **inside the project's own CI job**, so that `composer update` and `yarn install` use the same PHP, Node
and Yarn versions as the project.

## Tools

Rustine **installs nothing**: the environment that runs it must already provide the tools of the project, in the
versions the project expects (CI image, `mise install`, Volta, Corepack…, before Rustine starts). Commands run as they
are, with the tools found on the `PATH` unless `--yarn-bin`, `--node-bin` or `--composer-bin` point elsewhere.

Before fixing a group, Rustine checks the environment and stops with an explicit error when:

- `node` or `yarn` (Yarn projects), or Composer (Composer projects), cannot be run;
- the Yarn of the environment does not match `yarn.lock`: a Berry lockfile (`__metadata`) needs Yarn 2+, a Classic
  lockfile (`# yarn lockfile v1`) needs Yarn 1. The fix ladder (Berry or Classic) is chosen from the lockfile.

The versions found are written in the merge request description.
