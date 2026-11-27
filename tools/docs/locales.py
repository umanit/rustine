"""Languages of the documentation, shared by `split_readme.py` and `docs_tool.py` (standard library only).

English is the source language and lives at the root of the site. The other languages are the locales the application
ships, i.e. the catalogs `resources/translations/rustine+intl-icu.<locale>.yaml`: adding a catalog adds a language.
Each one needs, besides its translated pages in `docs/<locale>/`:

- an entry in `docs/locales.json` (`name` in the language selector, `home` and `contents` titles);
- a `zensical.<locale>.toml`, a translated copy of `zensical.fr.toml`.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOCS = ROOT / "docs"
SOURCE = "en"
CATALOGS = ROOT / "resources" / "translations"
METADATA = DOCS / "locales.json"
CATALOG = re.compile(r"^rustine\+intl-icu\.([A-Za-z0-9_]+)\.yaml$")


def fail(message: str) -> None:
    sys.exit(f"docs: {message}")


def translations() -> list[str]:
    """The languages other than English, from the catalogs of the application."""
    found = {m.group(1) for path in CATALOGS.iterdir() if (m := CATALOG.match(path.name))}

    return sorted(found - {SOURCE})


def languages() -> list[str]:
    return [SOURCE, *translations()]


def pages_dir(lang: str) -> Path:
    return DOCS if lang == SOURCE else DOCS / lang


def config_path(lang: str) -> Path:
    return ROOT / ("zensical.toml" if lang == SOURCE else f"zensical.{lang}.toml")


def metadata(lang: str) -> dict[str, str]:
    data = json.loads(METADATA.read_text(encoding="utf-8")) if METADATA.is_file() else {}
    entry = data.get(lang)
    if not isinstance(entry, dict) or not {"name", "home", "contents"} <= entry.keys():
        fail(f"{METADATA.relative_to(ROOT)} has no complete entry (name, home, contents) for `{lang}`")

    return entry
