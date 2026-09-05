# Listing Publish Rules (Seats Broker)

Any code path that publishes an XS2 ticket listing to Seats Broker (SB) **must** satisfy these rules. If any rule fails, **do not call** the SB Seller API (`POST /api/ticket/create` or update equivalents).

## Enforcement

| Layer | Class | Role |
|-------|-------|------|
| Pre-flight | `ListingPublishValidator` | Validates mapping, ticket fields, and transformed payload |
| Transform | `Xs2SellerListingTransformer` | Builds SB payload with XS2 `category_name`; best-effort dropdown match for logging |
| Job | `PushXs2TicketToSellerApi` | Runs validator → transform → validate payload → HTTP |
| Split | `SplitListingService` | Same validator + transformer gates for split listings |

## Required SB create payload fields

| Field | Type | Source |
|-------|------|--------|
| `match_id` | integer | Confirmed `EventMapping.m_id` |
| `category_name` | string | XS2 ticket/inventory `category_name` (required on publish) |
| `ticket_category` | integer | Optional — resolved for logging only; not sent in current publish payload |
| `ticket_type` | integer | SB dropdown match on XS2 ticket type |
| `split_type` | integer | SB dropdown match on XS2 flags |
| `seller_reference` | string | Stable XS2 external reference |
| `seller_id` | integer | Configured SB seller ID |
| `quantity` | integer | Remaining sellable quantity (0 to disable) |
| `price` | number/string | Package/net/face value in SB units |
| `price_type` | string | XS2 currency code |
| `facevalue` | number/string | Face value in SB units |
| `status` | `'0'` \| `'1'` | Active listing flag |

Optional: `ticket_block`, `ticket_row`, `ticket_details`, `home_town` (home team name string, e.g. `"Arsenal"`; empty when unknown).

**Publish payload:** Send `category_name` (XS2 inventory name). Do not include numeric `ticket_category` — SB create accepts `category_name` alone and may create the match category on the server (legacy behaviour).

SB can also accept `ticket_category` as an **integer** catalog ID when you need LiveFootball multi-marketplace routing (`ticket_id`, `stadium_seat_id`, etc.). The transformer resolves IDs for logging/fuzzy-match only; the publish payload uses `category_name` only.

**Multi-marketplace (LiveFootball + StubHub / HelloTickets):** When an event is published to LiveFootball together with external marketplaces, SB needs `ticket_category` to build the native LiveFootball flat ticket payload (`ticket_id`, `stadium_seat_id`, `seat_category`, etc.). Without it, SB may route LiveFootball through the StubHub `ticket_groups` adapter instead.

## Category resolution

Resolve `ticket_category` from the SB `/api/ticket_dropdown` categories for the match, in order:

1. Confirmed / mapped `stadium_seat_id` (mapping details or candidate scores) when that ID exists in the dropdown
2. Fuzzy name match on the XS2 inventory name and mapped SB category names (exact → starts-with → contains → first-word)
3. **Similarity fallback** — best `similar_text` match when score ≥ `SELLER_API_TICKET_CATEGORY_SIMILARITY_THRESHOLD` (default 65); logs a warning
4. When the dropdown `category` array is **empty** but admin has a confirmed/mapped `stadium_seat_id`, use that ID (logs a warning)
5. When nothing matches, **publish with `category_name` only** (logs info) — same as legacy listings and admin “Uses XS2 category name” bypass. SB has no Seller API to create categories; SB may create them on `POST /api/ticket/create` when given a new name.

**SB limitation:** The Seller API has no endpoint to create match ticket categories explicitly. Categories are read via `/api/ticket_dropdown`. New names can still be pushed via `category_name` on create (SB-side behaviour).

## Publish gates (before transform)

1. Event mapping status is `mapped` or `created` with valid `m_id`.
2. Event is sellable (`Xs2Event::isSellable()`).
3. Mapping status allows publish (`canAutoPublish` / `isManualPublishable`).
4. XS2 `category_name` is non-empty.
5. Price and currency are present.

Split publish uses the same transformer and validator as 1:1 publish.

## Examples

**Allowed** — XS2 name fuzzy-matches dropdown:

```
XS2 category_name: "Longside Upper"
SB dropdown: [{ id: 4, category_name: "Longside Upper Tier" }]
→ category_name: "Longside Upper"
→ createListing(...) proceeds
```

**Allowed** — mapped stadium seat ID present in dropdown:

```
XS2 category_name: "Matchday Premium"
Mapped stadium_seat_id: 22
SB dropdown: [{ id: 22, category_name: "Category 1 Premium" }, ...]
→ ticket_category: 22
→ category_name: "Matchday Premium"
→ createListing(...) proceeds
```

**Allowed** — no SB dropdown match; push XS2 name (e.g. Ticket Plus):

```
XS2 category_name: "Ticket Plus"
SB dropdown: [{ id: 1, category_name: "Away" }, ...]  (no Ticket Plus)
→ category_name: "Ticket Plus"
→ createListing(...) proceeds (no ticket_category in payload)
```

**Allowed** — empty SB dropdown but confirmed stadium seat mapping (match_id 11544-style):

```
SB dropdown: category: []
Mapped stadium_seat_id: 88 (manually confirmed)
→ ticket_category: 88 (warning logged)
→ createListing(...) proceeds
```

**Allowed** — similarity fallback when strict fuzzy fails:

```
XS2 category_name: "Silver Club Grada"
SB dropdown: [{ id: 12, category_name: "Silv Club Grada" }]
→ ticket_category: 12 (warning logged)
→ createListing(...) proceeds
```

**Blocked** — missing XS2 inventory category name:

```
XS2 category_name: ""
→ ListingTransformationException: XS2 inventory category name is missing.
→ no Seller API HTTP call
```

## Admin split / quantity rules

Split publish uses the same transformer and validator. Failed creates without an SB listing ID are not fixed by qty-sync alone — re-resolve category and re-Publish (or run Seats Broker new listing publish after deploy).
