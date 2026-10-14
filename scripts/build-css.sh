#!/usr/bin/env bash
# Rebuilds resources/dist/toolkit.css — the prebuilt stylesheet McToolkitPlugin inlines on
# the toolkit pages — from resources/tailwind/toolkit.css and the classes used in the views.
# Run it after changing any Blade view (the result is committed and shipped in the zip).
#
# Usage: ./scripts/build-css.sh
# Needs Node.js; uses the same Tailwind version as the panel (4.3.0). Set TAILWIND to an
# installed `tailwindcss` CLI binary to skip npx.
# Keep this file LF-terminated: with CRLF the shebang resolves to "bash\r" and nothing runs.
set -euo pipefail
cd "$(dirname "$0")/.."

TAILWIND="${TAILWIND:-npx --yes @tailwindcss/cli@4.3.0}"

$TAILWIND -i resources/tailwind/toolkit.css -o resources/dist/toolkit.css --minify

echo "built resources/dist/toolkit.css ($(wc -c < resources/dist/toolkit.css | tr -d ' ') bytes)"
