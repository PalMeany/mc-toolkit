#!/usr/bin/env bash
# Rebuilds dist/minecraft-toolkit-v<version>.zip + update.json from a tagged checkout.
# Usage: ./scripts/build-release.sh [version]
# Needs `zip` (or Windows tar.exe / bsdtar) and sha256sum or shasum.
# Keep this file LF-terminated: with CRLF the shebang resolves to "bash\r" and nothing runs.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION="${1:-$(sed -n 's/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*/\1/p' plugin.json | head -n 1)}"
if [ -z "$VERSION" ]; then
    echo "error: could not read the version from plugin.json" >&2; exit 1
fi

NAME="minecraft-toolkit-v${VERSION}.zip"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
STAGE="$WORK/minecraft-toolkit"

mkdir -p dist "$STAGE"
cp -r ./* "$STAGE/"
rm -rf "$STAGE/dist" "$STAGE/scripts" "$STAGE/tests" "$STAGE/companion"

# zip -r updates an existing archive in place (stale entries survive), so start fresh.
rm -f "dist/$NAME"

if command -v zip >/dev/null 2>&1; then
    (cd "$WORK" && zip -rqX "$OLDPWD/dist/$NAME" minecraft-toolkit)
elif [ -f /c/Windows/System32/tar.exe ]; then
    (cd "$WORK" && /c/Windows/System32/tar.exe -a -cf "${OLDPWD}/dist/${NAME}" minecraft-toolkit)
else
    echo "error: need 'zip' or Windows bsdtar" >&2; exit 1
fi

if command -v sha256sum >/dev/null 2>&1; then
    SHA=$(sha256sum "dist/$NAME" | awk '{print $1}')
else
    SHA=$(shasum -a 256 "dist/$NAME" | awk '{print $1}')
fi

URL="https://raw.githubusercontent.com/PalMeany/mc-toolkit/main/dist/$NAME"
printf '{\n    "*": {\n        "version": "%s",\n        "download_url": "%s",\n        "sha256": "%s"\n    }\n}\n' \
    "$VERSION" "$URL" "$SHA" > update.json

echo "built dist/$NAME (sha256 $SHA) -> update.json updated"
