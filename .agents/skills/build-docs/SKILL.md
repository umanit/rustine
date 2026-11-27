---
name: build-docs
description: Builds the Rustine documentation site (Zensical, English + every shipped translation) from README.md — splits the README into pages, re-translates into every shipped language the pages whose English source changed, builds the site in every shipped language and prints the preview commands. Use when asked to generate, update or build the documentation / the docs site / GitHub Pages, or after any change to README.md.
---

# build-docs

`README.md` (English) is the source of truth: it is **split** into pages, never rewritten.

- Languages: English is the source. The other languages are the locales found in `resources/translations/`
  (`rustine+intl-icu.<locale>.yaml`, English excluded). `python3 tools/docs/docs_tool.py locales` lists them: this is
  the single source of truth, nothing else hardcodes a language.
- English pages: `docs/*.md` (generated, do not edit by hand). Pages of another locale: `docs/<locale>/*.md`.
- Configs: `zensical.toml` (EN → `site/`) and `zensical.<locale>.toml` (→ `site/<locale>/`). The `nav` and the
  language selector (`alternate`) blocks are generated (`BEGIN NAV` / `BEGIN ALTERNATE` markers).
- `docs/locales.json`: per language, the `name` shown in the language selector and the `home` and `contents` titles.
- Shared by all the builds: `docs/assets/` (CSS, JS) and `docs/img/` (logos).
- Scripts (Python standard library only, nothing to install): `tools/docs/split_readme.py`,
  `tools/docs/docs_tool.py` and `tools/docs/locales.py` (shared).
- The build goes through `build/docs/<language>` (`make docs` assembles them) because Zensical copies everything it finds
  in `docs_dir` and does not follow symlinks.

This skill is plain Markdown instructions: any coding agent that can read files and run shell commands can follow it.

## Procedure

Run everything from the repository root. Do not install anything: if `zensical` is missing, step 0 fails and the user
must be told.

0. **Tooling**: `python3 tools/docs/docs_tool.py check-tools`. On failure, stop and report the message (create a
   virtualenv and `pip install -r docs/requirements.txt`, or `nix shell nixpkgs#zensical`).
1. **Read** `README.md` and the code (`bin/rustine <command> --help`, `src/Command/*`, `src/GitLab/GitLabConfig.php`,
   environment variables) and note any README/code contradiction. Do not fix it silently: list it in the final report.
2. **New language?** If `docs_tool.py locales` lists a locale that has no `zensical.<locale>.toml` or no entry in
   `docs/locales.json`, add them first: an entry (`name`, `home`, `contents`, in that language) and a copy of
   `zensical.fr.toml` with `<locale>` in `site_url`, `docs_dir`, `site_dir` and `theme.language`, and its texts
   (`site_description`, theme toggle names) translated. Leave the generated blocks as they are.
3. **Split**: `python3 tools/docs/split_readme.py` (idempotent, regenerates everything: one page per `##` heading,
   kebab-case slug, `index.md` with the intro and a table of contents, `#anchor` links rewritten to the right page).
4. **Translate**: `python3 tools/docs/docs_tool.py stale` lists the translated pages that are missing or out of date as
   `<locale>/<slug>` (hashes are kept in `docs/.translation-cache.json`). For each one, read the English page
   `docs/<slug>.md` and write `docs/<locale>/<slug>.md`. For each page:
    - same structure (same headings and levels, same tables); in `index.md`, keep the logo lines and the empty
      `<!-- BEGIN TOC … -->` / `<!-- END TOC -->` block;
    - leave code blocks, commands, options (`--group`), YAML/JSON keys, environment variables and branch, label and
      package names untouched; comments inside code blocks stay in English;
    - technical tone, not formal; “merge request” and “lockfile” stay as is; follow the typographic conventions of the
      target language (for French: typographic apostrophe `’`, never `'`);
    - in the “needs manual attention” section, use the wording of that locale’s catalog
      (`resources/translations/rustine+intl-icu.<locale>.yaml`).

   Then run `python3 tools/docs/docs_tool.py record` (stores the hashes) and `python3 tools/docs/split_readme.py sync`
   (navs and tables of contents of the translations). If no page is stale, translate nothing.
5. **Build**: `make docs` (assembles, then builds EN and every translation with `--strict`). Any error or broken
   link/anchor stops everything: fix the cause (never disable strict mode) and run it again.
6. **Preview**: print these commands for the user.

```sh
# zensical not on the PATH? (the build installs nothing)
python3 -m venv .venv-docs && . .venv-docs/bin/activate && pip install -r docs/requirements.txt
# or with Nix: nix shell nixpkgs#zensical

make docs            # site/ (EN) and site/<locale>/ (translations), strict
make docs_serve                         # EN → http://localhost:8000
make docs_serve LOCALE=fr [PORT=8001]   # a translation → http://localhost:8001 (one server per language)
python3 -m http.server -d site 8002   # the whole site, language switcher included
```

7. **Final report**: pages generated, README/code inconsistencies, and what is left to do by hand (GitHub Pages source
   set to “GitHub Actions” in Settings → Pages, trimming the README if wanted).

## Rules

- Never push: leave `git push` to the user.
- CI (`.github/workflows/docs.yml`) rejects a `docs/` that differs from the output of `split_readme.py`: always commit
  the generated pages together with the README.
