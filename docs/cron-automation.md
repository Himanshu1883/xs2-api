# Cron automation & split qty sync

## Split listing quantity sync (`xs2-sb-listing-inventory`)

When XS2 stock changes for a **split-enabled** ticket that already has Seats Broker sublistings:

| Scenario | SB API | Local DB |
|----------|--------|----------|
| Stock **10 → 8** (split qty 2) | **DELETE** trailing split (S5 only) | S5 → `deleted`; S1–S4 stay `active` |
| Stock **10 → 5** with remainder | **DELETE** trailing splits; **UPDATE** last kept split if qty changes | Trailing rows deleted; remainder split updated |
| Stock **→ 0** | **DELETE** each split individually | All splits `deleted`; `split_enabled` cleared |
| Stock **0 → >0** (restock) | **CREATE** full split plan again | `PublishSplitListings` queued; `split_enabled` set on publish |
| Low stock (≤ `XS2_SPLIT_UNPUBLISH_STOCK_MAX`) | **DELETE** each split | Same as zero stock |
| Ticket/event unavailable (stock > 0) | **DISABLE** each split (soft) | Splits stay `active` locally |
| Stock increase | **CREATE** missing trailing splits only | New `listing_splits` rows |

Rules are implemented in `SplitListingService::syncListings()` and invoked by:

- Scheduled cron `xs2:sync-sb-listing-inventory` (masters + splits)
- `xs2:sync-split-listing-quantities` (splits only)
- Inventory sync side-effects when split tickets change

**Not** used for stock-driven unpublish: soft disable on SB (only for unavailable ticket/event).

## Start All pipeline

**Start All XS2 & SeatsBroker** (`POST /api/admin/cron-config/start-all`):

1. Restores cron flags from the snapshot saved by Stop All (or env defaults)
2. Forces `APP_SCHEDULER_ENABLED=true` in `integration_settings`
3. Applies balanced queue profile and clears worker restart signals
4. Queues `BootstrapCronsAfterStartJob` **after HTTP response** (no 502)

Bootstrap sequence (when each cron flag is enabled):

| Delay | Cron job | Command |
|-------|----------|---------|
| 0s | `xs2-inventory-full` | `xs2:sync-inventory --mode=full` |
| 120s | `xs2-sb-new-listing-publish` | `xs2:publish-new-sb-listings` |
| 300s | `xs2-sb-listing-inventory` | `xs2:sync-sb-listing-inventory` |
| 330s | `xs2-sb-order-sync` | `seller-api:sync-bookings` |
| 360s | `xs2-sb-order-guest-data-sync` | `xs2:sync-order-guest-data` |

After bootstrap, the OS scheduler (`php artisan schedule:run` every minute) continues each job on its configured interval. Schedule definitions always register in `routes/console.php`; runtime `->when()` gates honour Stop/Start without restarting PHP workers.

**Manual-only crons** (never in Start All bootstrap): `xs2-events-sync`, `sb-events-sync`, `xs2-sb-failed-listing-publish-retry` (opt-in via admin toggle).

| Interval | Cron job | Command |
|----------|----------|---------|
| 30m (default) | `xs2-sb-failed-listing-publish-retry` | `xs2:retry-failed-listing-publish` |

The main **xs2-sb-new-listing-publish** cron permanently skips tickets with `sync_status=failed` or `split_sync_status=failed`. Use the retry cron to re-attempt those tickets.

## 502 prevention

| Requirement | Implementation |
|-------------|----------------|
| `QUEUE_CONNECTION=database` | Dockerfile / Railway default |
| Run now non-blocking | `RunAdminCronJob::dispatch()->afterResponse()` |
| Start All non-blocking | `BootstrapCronsAfterStartJob::dispatch()->afterResponse()` |
| Heavy crons off HTTP thread | `RunAdminCronJob` on `xs2.admin_cron_queue` |
| SB publish/qty jobs throttled | Staggered `delay()` on `seller-api` queue |

**Never** set `QUEUE_CONNECTION=sync` in production — Run now will block until the cron finishes.

## Railway env vars to verify

```env
QUEUE_CONNECTION=database
APP_SCHEDULER_ENABLED=true          # or rely on Start All override
XS2_ENABLED=true
SELLER_API_ENABLED=true
XS2_SB_LISTING_INVENTORY_SYNC_ENABLED=true
XS2_SB_NEW_LISTING_PUBLISH_ENABLED=true
XS2_SB_FAILED_LISTING_PUBLISH_RETRY_ENABLED=false
XS2_SB_BOOKINGS_SYNC_ENABLED=true   # enable for order sync cron
XS2_SB_ORDER_GUEST_DATA_SYNC_ENABLED=true
```

Optional:

```env
XS2_SPLIT_UNPUBLISH_STOCK_MAX=0     # 0 = disabled; >0 deletes all splits when stock ≤ threshold
```

## What to do now — testing checklist

### 1. Redeploy

1. Push API + web; redeploy Railway (xs2-api) and Vercel (xs2-web).
2. Confirm workers are running (`docker-entrypoint.sh` starts `queue:work` for `default` and `seller-api`).
3. In admin **Cron Config**, verify **Scheduler: enabled** and tasks show a **Next run** time (not “Never”).

### 2. Start All

1. If crons were stopped: click **Stop All**, then **Start All XS2 & SeatsBroker**.
2. Refresh cron dashboard — scheduler enabled, `bootstrap_queued: true` in API response.
3. Check execution logs within ~6 minutes for bootstrap runs (`trigger: start_all`).

### 3. Inventory sync

```sql
SELECT resource, status, last_attempted_at, last_successful_at, last_error
FROM xs2_sync_states
WHERE resource LIKE '%inventory%' OR resource LIKE 'sb-listings%';
```

- Run **xs2-inventory-full** (or wait for bootstrap).
- Confirm `xs2_event_inventory_sync_states.tickets_last_full_sync_at` updates.

### 4. Push inventory to SB

- Enable split on a mapped ticket; publish splits.
- Run **xs2-sb-new-listing-publish** or wait for scheduled run.
- Verify `listing_splits.seatsbroker_listing_id` populated and SB shows listings.

### 5. Qty update of pushed listings

1. Lower XS2 stock on a split ticket (e.g. 10 → 8).
2. Run inventory sync, then **xs2-sb-listing-inventory** (or wait).
3. Expected SB calls:
   - **DELETE** for removed trailing split only (e.g. S5)
   - **UPDATE** only if qty/price changed on kept splits
4. Verify locally:

```sql
SELECT split_order, quantity, status, seatsbroker_listing_id, sync_status
FROM listing_splits
WHERE master_listing_id = <ticket_id>
ORDER BY split_order;
```

5. Set stock to **0** → each split gets **DELETE** (not disable); all rows `status=deleted`.

### 6. Orders sync

1. Enable **SB order sync** cron in admin if disabled.
2. Run **xs2-sb-order-sync** or wait for schedule.
3. Check `sb_orders` for new/updated rows and `sync_status`.

### 7. Send orders to XS2

1. Run **xs2-sb-order-guest-data-sync** (or wait for bootstrap + schedule).
2. On SB Orders admin page, confirm XS2 push status / payloads on order rows.
3. Use **Create manual** for a single order if needed.

### SQL quick checks

```sql
-- Pending split qty sync
SELECT t.id, t.external_ticket_id, t.stock, t.split_enabled, t.split_sync_status
FROM xs2_tickets t
WHERE t.split_enabled = 1
  AND EXISTS (
    SELECT 1 FROM listing_splits ls
    WHERE ls.master_listing_id = t.id
      AND ls.status = 'active'
      AND ls.seatsbroker_listing_id IS NOT NULL
  );

-- Queue depth
SELECT queue, COUNT(*) AS pending FROM jobs GROUP BY queue;

-- Recent cron runs
SELECT cron_job_id, trigger, status, started_at, finished_at, message
FROM cron_execution_logs
ORDER BY id DESC LIMIT 20;
```
