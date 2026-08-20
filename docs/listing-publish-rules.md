# Listing Publish Rules (Seats Broker)

Any code path that publishes an XS2 ticket listing to Seats Broker (SB) **must** satisfy these rules. If any rule fails, **do not call** the SB Seller API (`POST /api/ticket/create` or update equivalents).

## Enforcement

| Layer | Class | Role |
|-------|-------|------|
| Pre-flight | `ListingPublishValidator` | Validates mapping, ticket fields, and transformed payload |
| Transform | `Xs2SellerListingTransformer` | Builds SB payload; resolves `ticket_category` from dropdown |
| Job | `PushXs2TicketToSellerApi` | Runs validator → transform → validate payload → HTTP |
| Split | `SplitListingService` | Same validator + transformer gates for split listings |

## Required SB create payload fields

| Field | Type | Source |
|-------|------|--------|
| `match_id` | integer | Confirmed `EventMapping.m_id` |
| `ticket_category` | integer (optional) | SB `ticket_dropdown.category[].id` when resolvable |
| `category_name` | string (optional) | XS2 `category_name` when dropdown ID cannot be resolved |
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

## Category resolution

When the SB ticket dropdown contains a matching category, publish with **`ticket_category`** (integer ID). When no dropdown match exists, publish with **`category_name`** set to the XS2 category name — no category-mapping table is required.

Resolution order in `Xs2SellerListingTransformer::tryResolveCategoryId`:

1. **Mapped seat IDs** — if category mapping has `stadium_seat_id` / details / candidate scores, try numeric ID match in dropdown.
2. **Name match** — normalized match of XS2 `category_name` against dropdown `category[].category_name`.
3. **Mapping hints** — stadium seat names from confirmed mapping details or ranked candidates.
4. **Fallback** — include `category_name` with the raw XS2 category name; omit `ticket_category`.

At least one of `ticket_category` or `category_name` must be present in the payload before calling SB.

## Publish gates (before transform)

1. Event mapping status is `mapped` or `created` with valid `m_id`.
2. Event is sellable (`Xs2Event::isSellable()`).
3. Mapping status allows publish (`canAutoPublish` / `isManualPublishable`).
4. XS2 `category_name` is non-empty.
5. Price and currency are present.

## Blocked vs allowed examples

**Allowed** — no dropdown match (category_name fallback):

```
XS2 category_name: "Corner"
SB dropdown categories: ["Away", "Home"]
→ category_name: "Corner" (no ticket_category)
→ createListing(...) proceeds; SB may accept or reject the name
```

**Allowed** — dropdown match:

```
XS2 category_name: "Longside Upper"
SB dropdown: [{ id: 4, category_name: "Longside Upper" }]
→ ticket_category: 4
→ createListing(...) proceeds
```

**Allowed** — mapping hint when raw name differs:

```
XS2 category_name: "Matchday Premium"
Mapping detail stadium_seat_name: "Category 1 Premium"
SB dropdown: [{ id: 22, category_name: "Category 1 Premium" }]
→ ticket_category: 22
```

## Admin split / quantity rules

Separate from SB contract rules: `ListingPublishRuleService` controls stock-based split vs single-listing publish plans. Those rules choose *how many* listings to create, not whether SB field requirements are met.
