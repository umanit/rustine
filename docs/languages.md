# Languages

The texts Rustine writes **in the target projects** (merge request title and description, notes, commit message) are
localized; English and French are shipped. The code, logs, console output, errors and this repository's own commits
stay in English. Technical logs of the strategies (in a collapsed block) are in English too.

To add a language, add one file: `resources/translations/rustine+intl-icu.<locale>.yaml`, a copy of the English one,
translated. The format is ICU (`{name}` placeholders; no plural/select for now; mind the apostrophe quoting rules,
and in French use the typographic `’`). A test enforces that every language has exactly the keys and placeholders of
English.
