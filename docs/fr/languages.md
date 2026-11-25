# Langues

Les textes que Rustine écrit **dans les projets cibles** (titre et description de la merge request, notes, message de
commit) sont localisés ; l’anglais et le français sont fournis. Le code, les logs, la sortie console, les erreurs et les
commits de ce dépôt restent en anglais. Les logs techniques des stratégies (dans un bloc replié) sont aussi en anglais.

Pour ajouter une langue, ajouter un fichier : `resources/translations/rustine+intl-icu.<locale>.yaml`, une copie du
fichier anglais, traduite. Le format est ICU (placeholders `{name}` ; pas de plural/select pour l’instant ; attention
aux règles de quoting de l’apostrophe, et en français utiliser l’apostrophe typographique `’`). Un test impose que
chaque langue ait exactement les clés et les placeholders de l’anglais.
