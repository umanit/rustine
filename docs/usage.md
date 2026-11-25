# Usage

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

## Following a run

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

## Dry run

`run --dry-run` reads a Trivy report and prints, for each group, the title, the severity, the commit message and the
description of the merge request that *would* be opened. Nothing is executed, so every fix is shown as *planned, not
verified*. Add `--report-file=preview.md` to keep the output.

## Configuration of `run`

Options take precedence over the variables GitLab CI provides.

| Option                       | Variable                                 | Default / meaning                                                                                                                                                                                      |
|------------------------------|------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `--group`                    |                                          | `critical-high` or `others` (repeatable; `run`: default every group in one merge request per ecosystem; `apply`: exactly one)                                                                          |
| `--ecosystem`                |                                          | `yarn` or `composer` (default: both)                                                                                                                                                                   |
| `--project`                  |                                          | Project directory (default `.`)                                                                                                                                                                        |
| `--gitlab-url`               | `CI_API_V4_URL`                          | GitLab API root, or the address of the instance (`/api/v4` is added when missing)                                                                                                                      |
| `--project-id`               | `CI_PROJECT_ID`                          | Project id or path                                                                                                                                                                                     |
| `--default-branch`           | `CI_DEFAULT_BRANCH`                      | Branch the fixes start from and the merge requests target                                                                                                                                              |
| *(no option, on purpose)*    | `RUSTINE_GITLAB_TOKEN` or `GITLAB_TOKEN` | Access token                                                                                                                                                                                           |
| `--locale`                   | `RUSTINE_LOCALE`                         | Language of the texts written in the project (default `en`)                                                                                                                                            |
| `--label`                    |                                          | Label of every merge request (repeatable, default `security`)                                                                                                                                          |
| `--severity-label-format`    |                                          | Default `severity::{severity}` → `severity::high`                                                                                                                                                      |
| `--assignee`, `--reviewer`   |                                          | Usernames, applied when a merge request is **created**                                                                                                                                                 |
| `--notify`                   |                                          | Usernames or groups (repeatable) mentioned (`@name`) in the description, to notify them, without assigning the merge request                                                                           |
| `--max-open-merge-requests`  |                                          | Stops opening new merge requests above this number                                                                                                                                                     |
| `--closed-grace-days`        |                                          | Does not open again a merge request closed by hand for the same fixes less than this many days ago (default `30`, `0` = always open)                                                                   |
| `--git-author-name/-email`   |                                          | Default: the token owner (commit email, else its GitLab private commit email `<id>-<user>@users.noreply.<host>`)                                                                                       |
| `--remote`                   |                                          | Git remote (default `origin`)                                                                                                                                                                          |
| `--report-file`              |                                          | Markdown report: status and description of each group                                                                                                                                                  |
| `--report`                   |                                          | Trivy report: written by a real run, read by `--dry-run`                                                                                                                                               |
| `--composer-bin`             | `RUSTINE_COMPOSER_BIN`                   | Command that runs Composer (default `composer`), e.g. `/usr/bin/php8.1 /usr/local/bin/composer2` to pick the PHP version; words separated by spaces, no quoting                                        |
| `--yarn-bin`                 | `RUSTINE_YARN_BIN`                       | Command that runs Yarn (default `yarn`), e.g. `/opt/yarn/bin/yarn`; words separated by spaces, no quoting                                                                                              |
| `--node-bin`                 | `RUSTINE_NODE_BIN`                       | Command that runs Node (default `node`); given by path, its directory goes first on the `PATH` of Yarn                                                                                                 |
| `--verify`                   | `RUSTINE_VERIFY`                         | When Trivy confirms the fixes: `final` (default, once when every package is handled), `each` (after every attempt the lockfile accepts: slow, the most certain) or `none` (the lockfile alone decides) |
| `--include-dev`              | `RUSTINE_INCLUDE_DEV`                    | Also scan the development dependencies (`require-dev`, `devDependencies`): see below. Off by default                                                                                                   |
| `--trivy-bin`, `--trivy-arg` |                                          | Trivy binary and extra arguments (repeatable), e.g. `--trivy-arg=--ignorefile=.trivyignore`                                                                                                            |

Labels are never translated (filters, boards and the open-merge-request limit depend on their names), nor are branch
names, CVE identifiers, versions or commands.

## Development dependencies

Trivy leaves the development dependencies out by default, and so does Rustine: a vulnerable `require-dev` package gets
no merge request. `--include-dev` (or `RUSTINE_INCLUDE_DEV=1`) is an opt-in that adds `--include-dev-deps` to every
Trivy run, the scan and the re-scans alike. Those packages are then fixed like any other (a `require-dev` constraint is
raised with `composer require --dev`) and listed in the same merge request. It is accepted by `scan`, `run` and `apply`:
give it to `apply` as well when its report was made with it, otherwise the re-scans do not see the development
dependencies.

Observed with Trivy 0.72: it marks the development packages of a `composer.lock` (`Dev: true`), while the results on a
Yarn project were the same with and without the option.

## Exit codes

| Code | Meaning                                                                                                                 |
|------|-------------------------------------------------------------------------------------------------------------------------|
| `0`  | Done, nothing left for a human                                                                                          |
| `1`  | A group failed (git, GitLab, lockfile consistency…) or the command was misused                                          |
| `2`  | Everything that could be fixed was; some packages need a manual action, or a group was skipped (limit, foreign commits) |

In GitLab CI, `allow_failure: { exit_codes: [2] }` turns code `2` into an orange job instead of a red one.

## Creating the token

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

## What runs during a fix

Rustine runs the tools of the project, and so the code they load. Know what that is before giving it a token:

| Tool     | Code of the project that runs                                                                                                                                                                                                           |
|----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Yarn     | Lifecycle scripts are disabled, but the plugins listed in `.yarnrc.yml` are loaded (they are committed in `.yarn/plugins/`)                                                                                                             |
| Composer | Scripts and plugins are disabled. For a project that sets `extra.symfony.require`, plugins are enabled to let Symfony Flex work: a Flex installed globally is the only plugin loaded, a `vendor/` also loads the plugins of the project |
| Trivy    | None: it only reads the lockfiles                                                                                                                                                                                                       |

The code comes from the default branch (the job runs there), so it is code that has already been merged, and the
plugins it can load are the ones the project already trusts in its own CI. The difference with the other jobs
is the token: keep the schedule on the default branch, and a protected variable.

## Example job

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
