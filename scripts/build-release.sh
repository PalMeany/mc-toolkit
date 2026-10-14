#!/usr/bin/env bash
# Rebuilds dist/minecraft-toolkit-v<version>.zip + update.json from a tagged checkout.
# Usage: ./scripts/build-release.sh [version]
# Requires Windows tar.exe (bsdtar) or GNU bsdtar; falls back to zip if present.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION="${1:-$(node -e "const fs=require('fs');console.log(JSON.parse(fs.readFileSync('plugin.json','utf8')).version)")}"
NAME="minecraft-toolkit-v${VERSION}.zip"
STAGE="$(mktemp -d)/minecraft-toolkit"

mkdir -p dist "$STAGE"
cp -r ./* "$STAGE/"
rm -rf "$STAGE/dist" "$STAGE/scripts"

if command -v zip >/dev/null 2>&1; then
    (cd "$(dirname "$STAGE")" && zip -rqX "$OLDPWD/dist/$NAME" minecraft-toolkit)
elif [ -f /c/Windows/System32/tar.exe ]; then
    (cd "$(dirname "$STAGE")" && /c/Windows/System32/tar.exe -a -cf "${OLDPWD}/dist/${NAME}" minecraft-toolkit)
else
    echo "error: need 'zip' or Windows bsdtar" >&2; exit 1
fi

SHA=$(sha256sum "dist/$NAME" | awk '{print $1}')
URL="https://raw.githubusercontent.com/PalMeany/mc-toolkit/main/dist/$NAME"
printf '{\n    "*": {\n        "version": "%s",\n        "download_url": "%s",\n        "sha256": "%s"\n    }\n}\n' \
    "$VERSION" "$URL" "$SHA" > update.json

echo "built dist/$NAME (sha256 $SHA) -> update.json updated"
