#!/usr/bin/env python3
"""Helpers of the documentation build (standard library only, nothing is installed).

    docs_tool.py check-tools     fail clearly when `zensical` is not on the PATH
    docs_tool.py stale           list the pages whose French translation is missing or out of date
    docs_tool.py record [slug…]  remember the hash of the English source of the translated pages (all by default)
    docs_tool.py stage           assemble build/docs/en and build/docs/fr: pages + shared assets, one directory per build
"""

from __future__ import annotations

import hashlib
import json
import re
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOCS = ROOT / "docs"
DOCS_FR = DOCS / "fr"
CACHE = DOCS / ".translation-cache.json"
STAGE = ROOT / "build" / "docs"
SHARED = ("assets", "img")  # directories shared by both builds
TOC = re.compile(r"<!-- BEGIN TOC.*?<!-- END TOC -->\n?", re.S)


def fail(message: str) -> None:
    sys.exit(f"docs_tool: {message}")


def pages() -> list[str]:
    return sorted(path.stem for path in DOCS.glob("*.md"))


def source_hash(slug: str) -> str:
    """Hash of an English page; the generated contents of the home page are not part of what gets translated."""
    text = (DOCS / f"{slug}.md").read_text(encoding="utf-8")

    return hashlib.sha256(TOC.sub("", text).encode()).hexdigest()


def load_cache() -> dict[str, dict[str, str]]:
    return json.loads(CACHE.read_text(encoding="utf-8")) if CACHE.is_file() else {}


def stale() -> list[str]:
    cache = load_cache()

    return [
        slug
        for slug in pages()
        if not (DOCS_FR / f"{slug}.md").is_file() or cache.get(slug, {}).get("source_sha256") != source_hash(slug)
    ]


def check_tools() -> None:
    if shutil.which("zensical") is None:
        fail(
            "`zensical` is not on the PATH. This build installs nothing: provide it yourself, e.g.\n"
            "  python3 -m venv .venv-docs && .venv-docs/bin/pip install -r docs/requirements.txt\n"
            "  fish: source .venv-docs/bin/activate.fish\n"
            "or, with Nix: nix shell nixpkgs#zensical"
        )


def record(slugs: list[str]) -> None:
    cache = load_cache()
    for slug in slugs or pages():
        if slug not in pages():
            fail(f"unknown page `{slug}`")
        if not (DOCS_FR / f"{slug}.md").is_file():
            fail(f"docs/fr/{slug}.md does not exist: translate it before recording it")
        cache[slug] = {"source_sha256": source_hash(slug)}
    CACHE.write_text(json.dumps(cache, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def stage() -> None:
    """One directory per build, because Zensical copies everything it finds in `docs_dir`: the English build would
    publish `docs/fr/` as pages, and a symlink to the shared assets is not followed in the French one."""
    missing = [slug for slug in pages() if not (DOCS_FR / f"{slug}.md").is_file()]
    if missing:
        fail("missing French pages: " + ", ".join(f"docs/fr/{slug}.md" for slug in missing))

    shutil.rmtree(STAGE, ignore_errors=True)
    for lang, source in (("en", DOCS), ("fr", DOCS_FR)):
        target = STAGE / lang
        target.mkdir(parents=True)
        for page in source.glob("*.md"):
            shutil.copy2(page, target / page.name)
        for shared in SHARED:
            shutil.copytree(DOCS / shared, target / shared)
    print("staged build/docs/en and build/docs/fr")


def main() -> None:
    command, args = (sys.argv[1], sys.argv[2:]) if len(sys.argv) > 1 else ("", [])
    if command == "check-tools":
        check_tools()
    elif command == "stale":
        print("\n".join(stale()))
    elif command == "record":
        record(args)
    elif command == "stage":
        stage()
    else:
        fail(__doc__.strip().splitlines()[0] + " — see the docstring for the commands")


if __name__ == "__main__":
    main()
