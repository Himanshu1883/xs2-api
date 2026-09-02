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

# Optional: wipe pending jobs on container start (off by default — preserves backlogs across deploys).
if [ "${QUEUE_CLEAR_ON_START:-false}" = "true" ] || [ "${QUEUE_CLEAR_ON_START:-false}" = "1" ]; then
    php artisan queue:clear --force 2>/dev/null || true
fi

# Allow concurrent HTTP requests (default artisan serve is single-threaded without this).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

QUEUE_WORKER_SLEEP="${QUEUE_WORKER_SLEEP:-3}"
QUEUE_WORKER_MAX_JOBS="${QUEUE_WORKER_MAX_JOBS:-100}"
QUEUE_WORKER_TRIES="${QUEUE_WORKER_TRIES:-3}"
QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-300}"

SELLER_API_QUEUE="${SELLER_API_QUEUE:-seller-api}"
XS2_MAPPING_QUEUE="${XS2_MAPPING_QUEUE:-xs2-mapping}"
XS2_RECONCILE_QUEUE="${XS2_RECONCILE_QUEUE:-xs2-reconcile}"
XS2_LISTING_GEN_QUEUE="${XS2_LISTING_GEN_QUEUE:-xs2-listing-gen}"
XS2_QUEUE="${XS2_QUEUE:-xs2-sync}"
XS2_GUEST_QUEUE="${XS2_GUEST_QUEUE:-xs2-guest}"

# Spawn a restart loop for one or more queue:work processes.
# Clears the queue:restart cache before each start so workers respawned after
# admin "Stop all" are not stuck exiting on a stale restart signal.
start_worker_loop() {
    label="$1"
    queues="$2"
    (
        while true; do
            php artisan cache:forget illuminate:queue:restart 2>/dev/null || true
            php artisan queue:work \
                --queue="$queues" \
                --tries="$QUEUE_WORKER_TRIES" \
                --timeout="$QUEUE_WORKER_TIMEOUT" \
                --sleep="$QUEUE_WORKER_SLEEP" \
                --max-jobs="$QUEUE_WORKER_MAX_JOBS" \
                --max-time=3600 \
                || true
            echo "${label} queue worker exited, restarting in 2s..."
            sleep 2
        done
    ) &
    echo "Started ${label} queue worker (queues=${queues})"
}

ADMIN_CRON_QUEUE="${ADMIN_CRON_QUEUE:-admin-cron}"
GENERAL_QUEUES="${ADMIN_CRON_QUEUE},${XS2_MAPPING_QUEUE},${XS2_RECONCILE_QUEUE},${XS2_LISTING_GEN_QUEUE},${XS2_QUEUE},${XS2_GUEST_QUEUE},default"

# Dedicated seller-api worker so SB publish jobs are never starved behind xs2-sync backlogs
# or long-running default-queue admin crons (RunAdminCronJob timeout up to 900s).
start_worker_loop "seller-api" "$SELLER_API_QUEUE"

# General pipeline + default cron worker (seller-api handled above).
start_worker_loop "general" "$GENERAL_QUEUES"

# Start scheduler in background
php artisan schedule:work &

# Run web server in foreground (no exec — keeps background processes alive)
php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
