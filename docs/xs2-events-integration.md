# XS2 event integration

This integration imports and maps XS2 events only. Tickets, reservations, bookings, checkout, payments, guest data, and e-ticket downloads are intentionally not implemented.

## Setup

Configure these values (never commit a real key):

```env
XS2_BASE_URL=https://testapi.xs2event.com
XS2_API_KEY=
XS2_RATE_LIMIT_PER_MINUTE=30
XS2_RATE_LIMIT_PACING=true
XS2_SPORTS=soccer
```

For existing installations using the older `XS2EVENT_*` variable names, the
configuration accepts them as a temporary compatibility fallback. Prefer the
`XS2_*` names above for new deployments.

Run the migrations and clear configuration after setting the values:

```bash
php artisan migrate
php artisan config:clear
```

## Running imports

Run one soccer import in the current process:

```bash
php artisan xs2:sync-events --sport=soccer --sync
```

Queue an incremental import instead:

```bash
php artisan xs2:sync-events --sport=soccer
php artisan queue:work --queue=xs2-mapping,xs2-sync,seller-api,default
```

`--full` performs a full reconciliation. With no `--sport`, the command uses `XS2_SPORTS`.

The scheduler queues an incremental job hourly and a full reconciliation daily for each configured sport. Jobs use `withoutOverlapping`, `onOneServer`, and one unique queue-job key per sport so full and incremental imports cannot overlap. Run a scheduler worker in production:

```bash
php artisan schedule:work
```

## XS2 API and time handling

The importer calls `GET /v1/events` with `sport_type`, `page_size=50`, and `page`; it follows `pagination.next_page`. Incremental runs additionally use the documented `updated=ge:<timestamp>` filter, with a five-minute overlap. XS2 requires its timestamp to use `YYYY-MM-DD HH:MM:SS` rather than an ISO `T` separator. The successful checkpoint is set only after every page succeeds and is the sync start time.

XS2's event date fields have no timezone field in the supplied OpenAPI contract. `date_start` and `date_stop` are stored as their supplied local wall-clock values (`date_start_local` and `date_stop_local`) and are not silently converted to UTC. Frontend event and mapping responses serialize those values as timezone-free ISO local date-times (`YYYY-MM-DDTHH:mm:ss`); clients must render them as event-local wall-clock values rather than treating them as UTC instants.

Requests use the documented `X-Api-Key` header. Application-side rate limiting uses `XS2_RATE_LIMIT_PER_MINUTE` and paces requests through the shared cache by default; temporary connection failures and HTTP 429/500/502/503/504 receive exponential retry. API keys and raw payloads are never written to logs.

## Event mapping

This application has no `events` table or `Event` model. Its existing local-event record is legacy `match_info` with signed integer primary key `m_id`; mappings therefore use `event_mappings.m_id` with no foreign key. One local event may have only one active (`mapped` or `created`) XS2 mapping. The application enforces that invariant in the mapper and with a database constraint.

The background importer never creates `match_info` records. An administrator may explicitly create one only after resolving legacy catalog references: supplier team, city, category, and tournament labels are never written into legacy ID columns. A missing or invalid source start date is always left `pending`; it is never compared with the current time.

Candidates are restricted to a ±1 day `match_date` window. The score is weighted as: event name 30%, date/time 25%, home/away teams 25%, venue/city 10%, and tournament 10%. Text matching lowercases, ASCII-normalizes, removes punctuation and football suffixes, and normalizes `v`, `vs`, `versus`, and hyphens.

| Score | Result |
| --- | --- |
| 85–100 | automatic mapping |
| 65–84.99 | `pending` mapping with up to five candidates |
| under 65 | `pending` mapping with `no_reliable_local_candidate`; no local record is created |

Exact external-ID matches, or textual matches with the same local calendar date, teams, and compatible city, use the `exact` method. A pending mapping without a candidate includes the XS2 home team, away team, city, and tournament IDs/names in `match_details.local_references`, so they can be resolved to canonical legacy records before creation. `manual` and `ignored` mappings are never changed by automated runs. Mapping is protected by transactions and row locks where supported.

Mapping statuses are `mapped`, `pending`, `created`, and `ignored`; methods are `automatic`, `manual`, `created`, and `exact`.

The importer maps new events immediately and recalculates automatic mappings only when mapping-relevant XS2 fields change. Pending mappings are reconsidered on each sync so that newly added local events can be selected. Manual, ignored, and created decisions are retained until an authorized mapping action changes them.

## Admin review API

The admin endpoints in this section require `auth:sanctum` and the `admin`
middleware. An administrator obtains and revokes its bearer token through:

```text
POST /api/auth/login                                      {"email":"...","password":"..."}
POST /api/auth/logout
```

The admin route surface is:

```text
GET  /api/admin/events/categories
GET  /api/admin/events/tournaments?category_id={category}
GET  /api/admin/events/teams?search={query}
GET  /api/admin/events/cities?search={query}
GET  /api/admin/events/venues?city_id={city}&search={query}
GET  /api/admin/xs2/event-mappings
GET  /api/admin/xs2/event-mappings/summary
GET  /api/admin/xs2/event-mappings/{mapping}
POST /api/admin/xs2/event-mappings/{mapping}/map       {"event_id": 123}
POST /api/admin/xs2/event-mappings/{mapping}/create-event
POST /api/admin/xs2/event-mappings/{mapping}/ignore
POST /api/admin/xs2/event-mappings/{mapping}/reopen
POST /api/admin/xs2/event-mappings/{mapping}/recalculate {"force": false}
GET  /api/admin/events/search
GET  /api/admin/xs2/sync-status
POST /api/admin/xs2/sync-events                         {"sport":"soccer","full":false}
```

All mapping operations use `auth:sanctum`, the `admin` middleware, and the
`EventMappingPolicy`. A mapping response contains its ID, status, method,
score, normalized XS2 summary, local event, local candidate suggestions,
score breakdown, reviewer display name, and timestamps. It never includes the
XS2 `raw_payload`, API key, supplier database row ID, or sync errors. Pending
`match_details.local_references` deliberately includes supplier reference IDs
for the administrator to resolve the event against the legacy catalog.

The list supports `status`, `mapping_method`, `sport`, `date_from`, `date_to`,
`search`, `minimum_score`, `maximum_score`, `has_local_event`, `page`,
`per_page` (1-100), `sort` (`id`, `match_score`, `created_at`, `updated_at`),
and `direction` (`asc`, `desc`). Candidate events are batch-loaded so listing
mapping suggestions does not cause one local-event query per mapping.

`GET /api/admin/events/search` is the manual-mapping autocomplete endpoint.
It accepts `search`, `date_from`, `date_to`, `venue`, `tournament`, and
`limit` (1-50, default 20). The legacy `sport` parameter is accepted for
backward compatibility but cannot filter reliably because unmapped local events
have no canonical local sport field. Without dates it searches the limited range
from yesterday through the next three months and returns only local event ID,
name, start date, sport, venue, tournament, and teams.

`GET /api/admin/xs2/sync-status` returns one safe summary for each configured
`XS2_SPORTS` entry. It includes the resource, current status, attempt and
success timestamps, a sanitized failure notice, and only aggregate event
counts. It never exposes upstream responses, traces, request data, or keys.
`POST /api/admin/xs2/sync-events` accepts a configured `sport` and optional
boolean `full`, queues `SyncXs2EventsJob`, and responds with `202 Accepted`.
The endpoint acquires the job's own per-sport unique lock before dispatching,
so a queued or running request receives a clear non-dispatch response.
Synchronization is never run in the HTTP request.

`GET /api/admin/xs2/event-mappings/summary` returns only aggregate `total`,
`mapped`, `pending`, `created`, and `ignored` counts. It supports `sport`,
`date_from`, and `date_to`, so dashboard cards do not need to download mapping
pages solely to derive their totals.

Manual mapping validates `event_id` against local `match_info`, rejects a local
event that already has an active XS2 mapping, and updates the mapping
transactionally while retaining existing score details. Creating a local event
reuses `LocalEventCreator` and its external-ID duplicate check in a transaction.
It accepts optional local catalog IDs `home_team_id`, `away_team_id`, `city_id`,
`venue_id`, `category_id`, and `tournament_id`. Every supplied ID is verified against the
legacy catalog; fields that are non-nullable in the deployed `match_info`
schema must be supplied. If both a category and tournament are supplied, the
tournament must belong to that category.

The create-event UI should obtain those IDs from the admin catalog endpoints:
`/api/admin/events/teams`, `/cities`, `/venues`, `/categories`, and
`/tournaments`. Each reference endpoint returns only `{ "id", "name" }`
records. Teams, cities, and venues support `search` and `limit` (1-100);
venues additionally accept `city_id`. Tournaments require `category_id`.
They are admin-only and deliberately return local catalog IDs, never supplier
labels as writable references.
Ignoring clears the local mapping but keeps the synchronized XS2 record and
prevents future automatic remapping. It also queues disabling of any seller
listing that is no longer publishable. Reopen preserves candidates and any
existing local event, but rejects a `created` mapping to avoid orphaning or
duplicating that event. Recalculation reruns the local scoring service; a
manual mapping requires an explicit authorized `{ "force": true }`, a created
mapping cannot be recalculated, and an ignored mapping must be reopened first.

## Frontend API contract

The application uses an unversioned `/api` prefix. Do not introduce `/api/v1`
routes unless the whole API is versioned together.

### Public local events

```text
GET /api/events
GET /api/events/{event}
```

`{event}` is the local event ID (`match_info.m_id`) and is returned to clients
as `event.id`. It is the only identifier the frontend may use in local event
links or public event routes, for example `/events/123`. The XS2 database row
ID is never exposed by these endpoints and must not be used in frontend URLs.

The endpoints use the existing Laravel resource response shape:

```json
{
  "data": {
    "id": 123,
    "slug": null,
    "name": "Alpha FC vs Beta FC",
    "sport_type": "soccer",
    "starts_at": "2026-08-01T12:00:00",
    "ends_at": "2026-08-01T14:00:00",
    "date_confirmed": true,
    "status": "active",
    "venue": {
      "id": null,
      "name": "Stadium",
      "city": "London",
      "country_code": "GBR"
    },
    "tournament": { "id": null, "name": "Premier League" },
    "home_team": { "id": null, "name": "Alpha FC" },
    "away_team": { "id": null, "name": "Beta FC" },
    "description": null,
    "inventory": {
      "has_xs2_inventory": true,
      "ticket_count": 42,
      "minimum_price": 120,
      "maximum_price": 250,
      "currency": "EUR"
    }
  }
}
```

`GET /api/events` returns this resource in `data` and Laravel's normal
`links` and `meta` pagination fields. It returns canonical local `match_info`
records only: existing local records, locally created XS2 events, and local
records mapped to XS2 are each represented once. Raw XS2 rows are never added
as duplicate public events. Its `search`, `city`, `tournament`, and `venue`
filters match both legacy display-name rows and display names resolved through
legacy reference IDs.

### Response envelopes and errors

All API resource responses use Laravel's `data` envelope. Paginated lists add
the normal `meta` (`current_page`, `last_page`, `per_page`, `total`) and
`links` (`first`, `last`, `prev`, `next`) fields. Successful single-resource
and mutation responses add a human-readable top-level `message`, for example:

```json
{
  "message": "Event mapping updated successfully.",
  "data": {}
}
```

API validation failures always respond with HTTP 422 and the stable envelope
`{ "message": "The provided data is invalid.", "errors": { ... } }`.
Authorization failures use HTTP 403 with
`{ "message": "You are not authorized to perform this action." }`.
Authentication, missing-resource, method, rate-limit, and unexpected failures
are likewise JSON responses with safe messages only; exception messages,
traces, supplier data, and credentials are never returned to frontend clients.

By default the list includes upcoming local events and orders by `starts_at`
ascending. It accepts these schema-backed query parameters:

| Parameter | Supported values / behavior |
| --- | --- |
| `page` | Positive integer page number. |
| `per_page` | Integer from 1 to 100; defaults to 20. |
| `search` | Searches local name, teams, city, and tournament. |
| `sport` | Exact linked XS2 `sport_type`. |
| `date_from`, `date_to` | Inclusive local start-date bounds. `date_from` overrides the default upcoming lower bound. |
| `country` | Exact three-letter linked XS2 country code. |
| `city`, `tournament` | Partial match against canonical local fields. |
| `venue` | Partial match against linked XS2 venue name. |
| `status` | Exact linked XS2 event status. |
| `sort` | `starts_at`, `name`, or `id`. |
| `direction` | `asc` or `desc`. |
| `has_inventory` | `true`, `false`, `1`, or `0`, based on synced XS2 ticket count. |

There is no local slug column, so `slug` is currently `null` and frontend
navigation must use `event.id`. Supplier venue, tournament, and team IDs are
not exposed. The XS2 inventory price fields are source-provided whole-EUR
integers; they are summaries only, not supplier net rates. This is a
catalog-only public API: public ticket offers, reservations, checkout, orders,
payments, guest data, and e-ticket downloads are intentionally not exposed.

Ignored mappings, unavailable synced events (`cancelled`, `canceled`,
`deleted`, `hidden`, `inactive`), and events marked missing by synchronization
are excluded. `GET /api/events/{event}` performs the same availability check
and responds with a JSON 404 when the locally bound event is not public. It
uses only the synchronized local database and never calls XS2 at request time.

### Admin XS2 mappings

The existing mapping endpoints remain under `/api/admin/xs2/event-mappings`
and require an administrator bearer token. Mapping resources expose external
XS2 metadata only as `xs2_event.external_event_id`; they do not expose the XS2
database row ID. When a local event is present it is returned as
`local_event.id`, and manual mapping requests use `{ "event_id": 123 }`.
`{mapping}` identifies the admin-only event-mapping record, not an XS2 event
or a public local event.

Candidate details also use `candidate_event_id` or `event_id`, never the
legacy storage field name `m_id`. The frontend should use each of these local
IDs to request `/api/events/{event}` or construct its local event route.

## Tests

```bash
php artisan test
```
