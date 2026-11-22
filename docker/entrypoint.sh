#!/bin/sh
set -e

# The published image carries a placeholder instead of APP_SECRET. Each container replaces it
# with its own random value on first start, so web requests and `docker exec … bin/console`
# share the same secret and nobody can read it from the image.
if grep -q 'generated-on-first-start' /app/.env.local.php 2>/dev/null; then
    php -r '$f = "/app/.env.local.php"; file_put_contents($f, str_replace("generated-on-first-start", bin2hex(random_bytes(16)), file_get_contents($f)));'
fi

exec docker-php-entrypoint "$@"
