#!/usr/bin/env bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SAIL="${SCRIPT_DIR}/vendor/bin/sail"

# 1) Build Vite assets if manifest is missing (required by Blade/Inertia tests)
if [ ! -f "${SCRIPT_DIR}/public/build/manifest.json" ]; then
    echo "Building Vite assets..."
    "$SAIL" bash -c "cd /var/www/html && APP_ENV=testing npm run build"
fi

# 2) Cache testing config
"$SAIL" artisan config:cache --env=testing

# 3) Run tests (accept any args, e.g. a single file)
"$SAIL" artisan test --env=testing "$@"
EXIT_CODE=$?

# 4) Clear the cache so dev goes back to .env
"$SAIL" artisan config:clear

exit $EXIT_CODE
