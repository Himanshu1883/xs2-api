#!/bin/sh
set -e

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set. Run 'php artisan key:generate --show' locally and add it to Railway variables."
    exit 1
fi

php artisan package:discover --no-ansi
php artisan config:cache --no-ansi

if ! php artisan migrate --force --no-ansi; then
    echo "WARNING: migrate --force failed; continuing startup so /up can respond."
fi

php artisan route:cache --no-ansi

# Clear stale queue restart signals (left by admin "Stop" button clicks)
php artisan cache:forget illuminate:queue:restart 2>/dev/null || true

# Clear any stale pending jobs from previous deploys
php artisan queue:clear --force 2>/dev/null || true

# Allow concurrent HTTP requests (default artisan serve is single-threaded without this).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

QUEUE_WORKER_SLEEP="${QUEUE_WORKER_SLEEP:-3}"
QUEUE_WORKER_MAX_JOBS="${QUEUE_WORKER_MAX_JOBS:-100}"

# Start queue worker in background — restart loop so it survives max-jobs/max-time exits.
# seller-api is last so mapping/inventory jobs drain before SB API bursts.
(while true; do
  php artisan queue:work \
    --queue=xs2-mapping,xs2-reconcile,xs2-listing-gen,xs2-sync,xs2-guest,seller-api,default \
    --tries=3 \
    --timeout=300 \
    --sleep="$QUEUE_WORKER_SLEEP" \
    --max-jobs="$QUEUE_WORKER_MAX_JOBS" \
    --max-time=3600
  echo "Queue worker exited, restarting in 2s..."
  sleep 2
done) &

# Start scheduler in background
php artisan schedule:work &

# Run web server in foreground (no exec — keeps background processes alive)
php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
