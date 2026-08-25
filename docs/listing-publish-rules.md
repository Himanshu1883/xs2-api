# Listing Publish Rules (Seats Broker)

Any code path that publishes an XS2 ticket listing to Seats Broker (SB) **must** satisfy these rules. If any rule fails, **do not call** the SB Seller API (`POST /api/ticket/create` or update equivalents).

## Enforcement

| Layer | Class | Role |
|-------|-------|------|
| Pre-flight | `ListingPublishValidator` | Validates mapping, ticket fields, and transformed payload |
| Transform | `Xs2SellerListingTransformer` | Builds SB payload; resolves integer `ticket_category` + XS2 `category_name` |
| Job | `PushXs2TicketToSellerApi` | Runs validator → transform → validate payload → HTTP |
| Split | `SplitListingService` | Same validator + transformer gates for split listings |

## Required SB create payload fields

| Field | Type | Source |
|-------|------|--------|
| `match_id` | integer | Confirmed `EventMapping.m_id` |
| `ticket_category` | integer | SB ticket-dropdown category ID (≥ 1) |
| `category_name` | string | XS2 ticket/inventory `category_name` |
| `ticket_type` | integer | SB dropdown match on XS2 ticket type |
| `split_type` | integer | SB dropdown match on XS2 flags |
| `seller_reference` | string | Stable XS2 external reference |
| `seller_id` | integer | Configured SB seller ID |
| `quantity` | integer | Remaining sellable quantity (0 to disable) |
| `price` | number/string | Package/net/face value in SB units |
| `price_type` | string | XS2 currency code |
| `facevalue` | number/string | Face value in SB units |
| `status` | `'0'` \| `'1'` | Active listing flag |

Optional: `ticket_block`, `ticket_row`, `ticket_details`, `home_town`.

SB create/update requires `ticket_category` as an **integer** catalog ID (`"The ticket category must be an integer."` when omitted or sent as a string name). Always send both **`ticket_category` (int)** and **`category_name`** (XS2 inventory name).

## Category resolution

Resolve `ticket_category` from the SB `/api/ticket_dropdown` categories for the match, in order:

1. Confirmed / mapped `stadium_seat_id` (mapping details or candidate scores) when that ID exists in the dropdown
2. Fuzzy name match on the XS2 inventory name and mapped SB category names (exact → starts-with → contains → first-word)
3. **Similarity fallback** — best `similar_text` match when score ≥ `SELLER_API_TICKET_CATEGORY_SIMILARITY_THRESHOLD` (default 65); logs a warning
4. When the dropdown `category` array is **empty** but admin has a confirmed/mapped `stadium_seat_id`, use that ID (logs a warning)
5. Otherwise **fail locally** with a clear error listing available SB categories — do not invent a default ID and do not call SB

**SB limitation:** The Seller API has no endpoint to create match ticket categories (`POST /api/ticket/create` requires an existing integer `ticket_category` from the dropdown). Categories must be configured in SB admin for the match, or resolved via XS2 stadium seat mapping. Venue catalog sync (`GET /api/venues`) populates local `stadium_seats` for mapping UI only — it does not add categories to a match dropdown.

Cron / re-publish will keep failing until a resolvable integer category is available (map the category in admin, or use a name that matches the dropdown), then re-Publish.

## Publish gates (before transform)

1. Event mapping status is `mapped` or `created` with valid `m_id`.
2. Event is sellable (`Xs2Event::isSellable()`).
3. Mapping status allows publish (`canAutoPublish` / `isManualPublishable`).
4. XS2 `category_name` is non-empty.
5. Price and currency are present.
6. Transformed payload has a numeric `ticket_category` ≥ 1.

## Blocked vs allowed examples

**Allowed** — XS2 name fuzzy-matches dropdown:

```
XS2 category_name: "Longside Upper"
SB dropdown: [{ id: 4, category_name: "Longside Upper Tier" }]
→ ticket_category: 4
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

**Blocked** — no match (e.g. pending mapping, name not in dropdown):

```
XS2 category_name: "Matchday Premium"
SB dropdown: [{ id: 1, category_name: "Away" }]
→ ListingTransformationException: does not match a Seats Broker ticket_category ID ...
→ no Seller API HTTP call
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
