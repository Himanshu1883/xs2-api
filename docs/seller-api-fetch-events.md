# Seatsbrokers external events fetch (no DB)

Read-only pull of the Seatsbrokers **external** catalog (`GET /api/events`) using the Bearer token in `SELLER_API_KEY`. Nothing is written to MySQL or legacy `match_info` / `users` tables.

## Configure

In `.env`:

```env
SELLER_API_BASE_URL=https://externalapi.seatsbrokers.com
SELLER_API_KEY=<64-char hex bearer token>
```

Optional:

```env
SELLER_API_EVENTS_ENDPOINT=/api/events
SELLER_API_CATALOG_PER_PAGE=100
```

Listing publish (`/api/ticket/*`, `/api/ticket_dropdown`) uses **SELLER_API_LISTING_BASE_URL** (default `https://sandbox-sellerapi.seatsbrokers.com` for sandbox; production: `https://sellerapi.seatsbrokers.com`) with multipart form requests and the `apiKey` header (`SELLER_API_LISTING_API_KEY`, or `SELLER_API_KEY` when unset). Do not send listing calls to `externalapi.seatsbrokers.com` — those routes return HTTP 200 with `status: 0` and “route … could not be found”. Admins can override listing base URL and listing API key from the provider console **API Config** page (stored in `integration_settings`).

## Run

```bash
php artisan config:clear
php artisan seller-api:fetch-events
php artisan seller-api:sync-venues
```

`seller-api:fetch-events` remains read-only (optional JSON export).

Admins can also search and import individual catalog events from `/events` (**Get from Seats Broker**):

- `GET /api/admin/seller-api/events/search?q=` — name search (catalog is cached ~5 minutes; Seller API does not reliably filter by name, so matching is local)
- `POST /api/admin/seller-api/events/import` with `{ "event_id": "<md5 hash>" }` — idempotent insert into `match_info` using `m_id = reverse_md5(event_id)`, upserting teams / tournament / game_category / stadium (+ blocks via venue catalog when missing)

`seller-api:sync-venues` calls `GET /api/venues` and upserts:

- venues → `stadium`
- seat categories (from block `category` ids) → `stadium_seats`
- sections/blocks → `stadium_details`

Admins can also trigger the same sync from `/events/venues` (**Sync from Seatsbroker**) or `/admin/xs2/venue-mappings` (**Sync Seatsbroker catalogue**), which posts to `POST /api/admin/seller-api/sync-venues`.

Options for events fetch:

- `--save` — write `storage/app/exports/seller-api-events-<timestamp>.json`
- `--json` — print the full event array to stdout
- `--per-page=N` — pass `per_page` on each request (the API may ignore or cap it)

The command paginates using `meta.last_page` until all events are collected.
