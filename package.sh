#!/bin/bash
set -e

PLUGIN_SLUG="mi-plugin-airalo"
VERSION=$(sed -n 's/^ \* Version:[[:space:]]*\(.*\)/\1/p' mi-plugin-airalo.php)
TMP_DIR=$(mktemp -d)
DEST="$TMP_DIR/$PLUGIN_SLUG"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"

echo "→ Empaquetando $PLUGIN_SLUG v$VERSION ..."

if command -v composer &>/dev/null; then
    composer install --no-dev --quiet
else
    echo "  (composer no instalado, se usa vendor/ existente)"
fi

rsync -a ./ "$DEST/"
rm -f "$DEST/.env" "$DEST/dispositivos-api.php"
cd "$TMP_DIR"
zip -r "/tmp/$ZIP_NAME" "$PLUGIN_SLUG/" > /dev/null
rm -rf "$TMP_DIR"

echo "✓ /tmp/$ZIP_NAME"
