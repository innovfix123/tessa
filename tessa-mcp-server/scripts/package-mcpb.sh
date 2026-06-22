#!/usr/bin/env bash
# Build the Tessa MCP server as a Claude Desktop .mcpb bundle.
# Output: ../public/downloads/tessa-<version>.mcpb
set -euo pipefail

HERE="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
DOWNLOADS="$ROOT/public/downloads"

VERSION="$(node -p "require('$HERE/package.json').version")"
NAME="tessa-$VERSION"

if [ ! -f "$HERE/manifest.json" ]; then
    echo "ERROR: manifest.json missing at $HERE" >&2
    exit 1
fi

echo "==> Building TypeScript"
cd "$HERE"
rm -rf dist
npm run build

STAGE_PARENT="$(mktemp -d)"
STAGE="$STAGE_PARENT/$NAME"
echo "==> Staging bundle at $STAGE"
mkdir -p "$STAGE/server"

cp "$HERE/manifest.json" "$STAGE/manifest.json"
cp -R "$HERE/dist/." "$STAGE/server/"
cp "$HERE/package.json" "$STAGE/package.json"
[ -f "$HERE/package-lock.json" ] && cp "$HERE/package-lock.json" "$STAGE/package-lock.json"

echo "==> Installing production deps in stage"
( cd "$STAGE" && npm ci --omit=dev --silent --no-audit --no-fund )

mkdir -p "$DOWNLOADS"
BUNDLE="$DOWNLOADS/$NAME.mcpb"
rm -f "$BUNDLE"

echo "==> Zipping $BUNDLE"
( cd "$STAGE" && zip -qr "$BUNDLE" . )

echo "==> Cleaning stage"
rm -rf "$STAGE_PARENT"

if [ -d "$DOWNLOADS" ] && getent passwd www-data >/dev/null 2>&1 && [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data "$DOWNLOADS"
fi

ls -lh "$BUNDLE"
echo "==> Done. Version $VERSION (.mcpb)"
