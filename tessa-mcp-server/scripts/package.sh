#!/usr/bin/env bash
# Build + bundle the Tessa MCP server into a tarball under
# ../public/downloads/ so the portal page can serve it to end users.
set -euo pipefail

HERE="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
DOWNLOADS="$ROOT/public/downloads"

VERSION="$(node -p "require('$HERE/package.json').version")"
NAME="tessa-mcp-server-$VERSION"

echo "==> Building TypeScript"
cd "$HERE"
rm -rf dist
npm run build

STAGE="$(mktemp -d)/tessa-mcp-server"
echo "==> Staging at $STAGE"
mkdir -p "$STAGE"

cp -R "$HERE/dist" "$STAGE/dist"
cp "$HERE/package.json" "$STAGE/package.json"
[ -f "$HERE/package-lock.json" ] && cp "$HERE/package-lock.json" "$STAGE/package-lock.json"
cp "$HERE/.env.example" "$STAGE/.env.example"
cp "$HERE/README.md" "$STAGE/README.md" 2>/dev/null || true

echo "==> Installing production deps in stage"
( cd "$STAGE" && npm ci --omit=dev --silent --no-audit --no-fund )

mkdir -p "$DOWNLOADS"
TARBALL="$DOWNLOADS/$NAME.tgz"
echo "==> Creating $TARBALL"
( cd "$(dirname "$STAGE")" && tar -czf "$TARBALL" "$(basename "$STAGE")" )

echo "==> Cleaning stage"
rm -rf "$(dirname "$STAGE")"

if [ -d "$DOWNLOADS" ] && getent passwd www-data >/dev/null 2>&1; then
    if [ "$(id -u)" = "0" ]; then
        chown -R www-data:www-data "$DOWNLOADS"
    fi
fi

ls -lh "$TARBALL"
echo "==> Done. Version $VERSION"
