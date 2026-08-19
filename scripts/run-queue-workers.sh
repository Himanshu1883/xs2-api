#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ -f .env ]]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

APP_LOW_LOAD_MODE="${APP_LOW_LOAD_MODE:-false}"

if [[ "$APP_LOW_LOAD_MODE" == "true" || "$APP_LOW_LOAD_MODE" == "1" ]]; then
  XS2_SYNC_WORKERS="${XS2_SYNC_WORKERS:-1}"
  XS2_GUEST_WORKERS="${XS2_GUEST_WORKERS:-1}"
  XS2_MAPPING_WORKERS="${XS2_MAPPING_WORKERS:-1}"
  SELLER_API_WORKERS="${SELLER_API_WORKERS:-1}"
  DEFAULT_QUEUE_WORKERS="${DEFAULT_QUEUE_WORKERS:-1}"
else
  XS2_SYNC_WORKERS="${XS2_SYNC_WORKERS:-2}"
  XS2_GUEST_WORKERS="${XS2_GUEST_WORKERS:-1}"
  XS2_MAPPING_WORKERS="${XS2_MAPPING_WORKERS:-1}"
  SELLER_API_WORKERS="${SELLER_API_WORKERS:-1}"
  DEFAULT_QUEUE_WORKERS="${DEFAULT_QUEUE_WORKERS:-1}"
fi

QUEUE_WORKER_TRIES="${QUEUE_WORKER_TRIES:-5}"
QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-300}"
QUEUE_WORKER_SLEEP="${QUEUE_WORKER_SLEEP:-3}"

XS2_QUEUE="${XS2_QUEUE:-xs2-sync}"
XS2_GUEST_QUEUE="${XS2_GUEST_QUEUE:-xs2-guest}"
XS2_MAPPING_QUEUE="${XS2_MAPPING_QUEUE:-xs2-mapping}"
SELLER_API_QUEUE="${SELLER_API_QUEUE:-seller-api}"

COMMON=(--tries="$QUEUE_WORKER_TRIES" --timeout="$QUEUE_WORKER_TIMEOUT" --sleep="$QUEUE_WORKER_SLEEP")
PIDS=()

start_workers() {
  local count="$1"
  local queue="$2"
  local label="$3"
  local i

  if [[ "$count" -le 0 ]]; then
    echo "Skipping ${label} (0 workers — profile may disable this queue)"
    return
  fi

  for ((i = 1; i <= count; i++)); do
    php artisan queue:work --queue="$queue" "${COMMON[@]}" &
    PIDS+=("$!")
    echo "Started ${label} worker ${i}/${count} (pid $!, queue=${queue})"
  done
}

echo "Low load mode: ${APP_LOW_LOAD_MODE}"
echo "XS2 rate limit: ${XS2_RATE_LIMIT_PER_MINUTE:-60}/min (shared across xs2-sync workers via cache)"
echo "Promote delayed inventory jobs first if needed: php artisan queue:promote-delayed --queue=${XS2_QUEUE}"
echo "Stop workers with: php artisan queue:restart (supervisor/systemd may respawn them — stop those services too)"
echo

start_workers "$XS2_SYNC_WORKERS" "$XS2_QUEUE" "xs2-sync"
start_workers "$XS2_GUEST_WORKERS" "$XS2_GUEST_QUEUE" "xs2-guest"
start_workers "$XS2_MAPPING_WORKERS" "$XS2_MAPPING_QUEUE" "xs2-mapping"
start_workers "$SELLER_API_WORKERS" "$SELLER_API_QUEUE" "seller-api"
start_workers "$DEFAULT_QUEUE_WORKERS" "default" "default"

echo
echo "Worker PIDs: ${PIDS[*]}"
echo "Press Ctrl+C to stop this script (workers keep running until queue:restart or process kill)."

wait
