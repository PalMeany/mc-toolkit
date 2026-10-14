#!/usr/bin/env bash
# Builds the companion bridge (companion/bukkit + companion/mod), copies the jars into
# resources/bridge/ and writes resources/bridge/manifest.json (version + sha256 + size),
# which the panel plugin checks before it installs a jar on a server.
#
# Usage: ./scripts/build-bridge.sh [--no-build]   (--no-build: only refresh the manifest)
# Keep this file LF-terminated: with CRLF the shebang resolves to "bash\r" and nothing runs.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION="$(tr -d ' \n\r' < companion/VERSION)"
OUT=resources/bridge
mkdir -p "$OUT"

if [ "${1:-}" != "--no-build" ]; then
    (cd companion/bukkit && ./gradlew build --quiet)
    (cd companion/mod && ./gradlew build --quiet)

    cp companion/bukkit/build/libs/MCToolkitBridge.jar "$OUT/MCToolkitBridge.jar"
    cp companion/mod/build/libs/mctoolkit-bridge.jar "$OUT/mctoolkit-bridge.jar"
fi

sha() {
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | awk '{print $1}'; else shasum -a 256 "$1" | awk '{print $1}'; fi
}
size() { wc -c < "$1" | tr -d ' '; }

B="$OUT/MCToolkitBridge.jar"
M="$OUT/mctoolkit-bridge.jar"

printf '{\n    "version": "%s",\n    "files": {\n        "bukkit": {"file": "MCToolkitBridge.jar", "sha256": "%s", "size": %s},\n        "mod": {"file": "mctoolkit-bridge.jar", "sha256": "%s", "size": %s}\n    }\n}\n' \
    "$VERSION" "$(sha "$B")" "$(size "$B")" "$(sha "$M")" "$(size "$M")" > "$OUT/manifest.json"

echo "bridge $VERSION -> $OUT/manifest.json"
cat "$OUT/manifest.json"
