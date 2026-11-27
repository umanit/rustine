#!/usr/bin/env python3
"""Helpers of the documentation build (standard library only, nothing is installed).

The languages are English (the source) and the locales of `resources/translations/`, see locales.py.

    docs_tool.py check-tools              fail clearly when `zensical` is not on the PATH
    docs_tool.py locales                  list the translated languages, one per line
    docs_tool.py stale [locale]           list the translations (`<locale>/<slug>`) missing or out of date
    docs_tool.py record [locale] [slug…]  remember the hash of the English source of the translated pages (all by default)
    docs_tool.py stage                    assemble build/docs/<language>: pages + shared assets, one directory per build
"""

from __future__ import annotations

import hashlib
import json
import re
import shutil
import sys

from locales import DOCS, ROOT, SOURCE, config_path, fail, languages, metadata, pages_dir, translations

CACHE = DOCS / ".translation-cache.json"  # {locale: {slug: {"source_sha256": …}}}
STAGE = ROOT / "build" / "docs"
SHARED = ("assets", "img")  # directories shared by all the builds
TOC = re.compile(r"<!-- BEGIN TOC.*?<!-- END TOC -->\n?", re.S)


def pages() -> list[str]:
    return sorted(path.stem for path in DOCS.glob("*.md"))


def source_hash(slug: str) -> str:
    """Hash of an English page; the generated contents of the home page are not part of what gets translated."""
    text = (DOCS / f"{slug}.md").read_text(encoding="utf-8")

    return hashlib.sha256(TOC.sub("", text).encode()).hexdigest()


def load_cache() -> dict[str, dict[str, dict[str, str]]]:
    return json.loads(CACHE.read_text(encoding="utf-8")) if CACHE.is_file() else {}


def target_locales(locale: str | None) -> list[str]:
    if locale is None:
        return translations()
    if locale not in translations():
        fail(f"`{locale}` is not a translated language: " + (", ".join(translations()) or "none"))

    return [locale]


def stale(locale: str | None = None) -> list[str]:
    cache = load_cache()

    return [
        f"{lang}/{slug}"
        for lang in target_locales(locale)
        for slug in pages()
        if not (pages_dir(lang) / f"{slug}.md").is_file()
        or cache.get(lang, {}).get(slug, {}).get("source_sha256") != source_hash(slug)
    ]


def check_tools() -> None:
    if shutil.which("zensical") is None:
        fail(
            "`zensical` is not on the PATH. This build installs nothing: provide it yourself, e.g.\n"
            "  python3 -m venv .venv-docs && .venv-docs/bin/pip install -r docs/requirements.txt\n"
            "  (then activate the virtualenv)\n"
            "or, with Nix: nix shell nixpkgs#zensical"
        )


def record(args: list[str]) -> None:
    locale = args[0] if args and args[0] in translations() else None
    slugs = args[1:] if locale else args
    cache = load_cache()
    for lang in target_locales(locale):
        for slug in slugs or pages():
            if slug not in pages():
                fail(f"unknown page `{slug}`")
            if not (pages_dir(lang) / f"{slug}.md").is_file():
                fail(f"docs/{lang}/{slug}.md does not exist: translate it before recording it")
            cache.setdefault(lang, {})[slug] = {"source_sha256": source_hash(slug)}
    CACHE.write_text(json.dumps(cache, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def stage() -> None:
    """One directory per build, because Zensical copies everything it finds in `docs_dir`: the English build would
    publish `docs/<locale>/` as pages, and a symlink to the shared assets is not followed in the others."""
    problems = []
    for lang in languages():
        metadata(lang)
        if not config_path(lang).is_file():
            problems.append(f"{config_path(lang).name} is missing (copy zensical.fr.toml and translate it)")
        problems += [
            f"docs/{lang}/{slug}.md is missing" for slug in pages() if not (pages_dir(lang) / f"{slug}.md").is_file()
        ]
    if problems:
        fail("cannot build:\n  " + "\n  ".join(problems))

    shutil.rmtree(STAGE, ignore_errors=True)
    for lang in languages():
        target = STAGE / lang
        target.mkdir(parents=True)
        for page in pages_dir(lang).glob("*.md"):
            shutil.copy2(page, target / page.name)
        for shared in SHARED:
            shutil.copytree(DOCS / shared, target / shared)
    print("staged " + ", ".join(f"build/docs/{lang}" for lang in languages()))


def main() -> None:
    command, args = (sys.argv[1], sys.argv[2:]) if len(sys.argv) > 1 else ("", [])
    if command == "check-tools":
        check_tools()
    elif command == "locales":
        print("\n".join(translations()))
    elif command == "stale":
        print("\n".join(stale(args[0] if args else None)))
    elif command == "record":
        record(args)
    elif command == "stage":
        stage()
    else:
        fail(__doc__.strip().splitlines()[0] + " — see the docstring for the commands")


if __name__ == "__main__":
    main()
