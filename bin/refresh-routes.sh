#!/bin/bash
# Safely rebuild Laravel route + config cache.
# Lints sources first, backs up the existing cache, restores on failure.
# Run after editing files in routes/ or config/.
set -euo pipefail

cd /var/www/Tessa

CACHE_DIR=bootstrap/cache
ROUTE_CACHE="$CACHE_DIR/routes-v7.php"
CONFIG_CACHE="$CACHE_DIR/config.php"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
blue()  { printf '\033[34m%s\033[0m\n' "$*"; }

blue "==> Linting route files"
fail=0
for f in routes/*.php routes/api/*.php; do
    if ! out=$(php -l "$f" 2>&1); then
        red "SYNTAX ERROR in $f"
        echo "$out"
        fail=1
    fi
done

blue "==> Linting config files"
for f in config/*.php; do
    if ! out=$(php -l "$f" 2>&1); then
        red "SYNTAX ERROR in $f"
        echo "$out"
        fail=1
    fi
done

if [ "$fail" -ne 0 ]; then
    red "Aborting: existing cache left intact. Fix the syntax errors above and re-run."
    exit 1
fi

# Back up existing caches so we can restore if rebuild fails.
[ -f "$ROUTE_CACHE" ]  && cp "$ROUTE_CACHE"  "$ROUTE_CACHE.bak"
[ -f "$CONFIG_CACHE" ] && cp "$CONFIG_CACHE" "$CONFIG_CACHE.bak"

restore_on_fail() {
    red "Cache rebuild failed — restoring previous cache."
    [ -f "$ROUTE_CACHE.bak" ]  && mv "$ROUTE_CACHE.bak"  "$ROUTE_CACHE"
    [ -f "$CONFIG_CACHE.bak" ] && mv "$CONFIG_CACHE.bak" "$CONFIG_CACHE"
    exit 1
}
trap restore_on_fail ERR

blue "==> Rebuilding route cache"
sudo -u www-data php artisan route:cache >/dev/null
[ -s "$ROUTE_CACHE" ] || { red "route cache empty"; false; }

blue "==> Rebuilding config cache"
sudo -u www-data php artisan config:cache >/dev/null
[ -s "$CONFIG_CACHE" ] || { red "config cache empty"; false; }

# Success — drop backups.
trap - ERR
rm -f "$ROUTE_CACHE.bak" "$CONFIG_CACHE.bak"

green "Done. Route + config cache rebuilt."
