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

TRIES="${QUEUE_WORKER_TRIES:-5}"
TIMEOUT="${QUEUE_WORKER_TIMEOUT:-300}"
SLEEP="${QUEUE_WORKER_SLEEP:-3}"
MAX_JOBS="${QUEUE_WORKER_MAX_JOBS:-500}"
MAX_TIME="${QUEUE_WORKER_MAX_TIME:-3600}"

# Keep worker counts low on a single Railway web service so php artisan serve
# still has CPU/RAM. Inventory sync and Seller API (publish + qty sync) each
# get a dedicated worker so they run in parallel without starving HTTP.
XS2_SYNC_WORKERS="${XS2_SYNC_WORKERS:-1}"
SELLER_API_WORKERS="${SELLER_API_WORKERS:-1}"
# Shared worker for listing-gen / reconcile / mapping / guest / default.
# Set AUX_QUEUE_WORKERS=0 to disable if memory is very tight.
AUX_QUEUE_WORKERS="${AUX_QUEUE_WORKERS:-1}"

XS2_QUEUE="${XS2_QUEUE:-xs2-sync}"
SELLER_API_QUEUE="${SELLER_API_QUEUE:-seller-api}"
XS2_LISTING_GEN_QUEUE="${XS2_LISTING_GEN_QUEUE:-xs2-listing-gen}"
XS2_RECONCILE_QUEUE="${XS2_RECONCILE_QUEUE:-xs2-reconcile}"
XS2_GUEST_QUEUE="${XS2_GUEST_QUEUE:-xs2-guest}"
XS2_MAPPING_QUEUE="${XS2_MAPPING_QUEUE:-xs2-mapping}"

# Combined aux queues: listing-gen and reconcile before guest/default so
# pipeline progress is not starved, but never ahead of dedicated sync/seller.
AUX_QUEUES="${XS2_MAPPING_QUEUE},${XS2_LISTING_GEN_QUEUE},${XS2_RECONCILE_QUEUE},${XS2_GUEST_QUEUE},default"

start_queue_workers() {
  count="$1"
  queue="$2"
  label="$3"
  i=1

  if [ "$count" -le 0 ]; then
    echo "Skipping ${label} workers (count=0)"
    return
  fi

  while [ "$i" -le "$count" ]; do
    (
      while true; do
        php artisan queue:work \
          --queue="$queue" \
          --tries="$TRIES" \
          --timeout="$TIMEOUT" \
          --sleep="$SLEEP" \
          --max-jobs="$MAX_JOBS" \
          --max-time="$MAX_TIME"
        echo "${label} worker ${i}/${count} exited, restarting in 2s..."
        sleep 2
      done
    ) &
    echo "Started ${label} worker ${i}/${count} (queue=${queue})"
    i=$((i + 1))
  done
}

echo "Starting lean parallel workers (sync=${XS2_SYNC_WORKERS}, seller-api=${SELLER_API_WORKERS}, aux=${AUX_QUEUE_WORKERS})"

start_queue_workers "$XS2_SYNC_WORKERS" "$XS2_QUEUE" "xs2-sync"
start_queue_workers "$SELLER_API_WORKERS" "$SELLER_API_QUEUE" "seller-api"
start_queue_workers "$AUX_QUEUE_WORKERS" "$AUX_QUEUES" "aux"

# Start scheduler in background
php artisan schedule:work &

# Run web server in foreground (no exec — keeps background processes alive)
php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
