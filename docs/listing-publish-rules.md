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
| `ticket_category` | **integer** | SB `ticket_dropdown.category[].id` — **required** |
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

## Category resolution (critical)

SB rejects creates without a valid **`ticket_category` integer**. Sending `category_name` alone fails with *"The ticket category field is required"*. Sending a string fails with *"must be an integer"*.

**Approved approach:** resolve XS2 `category_name` to an SB dropdown ID at publish time. No persistent category-mapping table is *required*, but mapping hints may be used when present.

Resolution order in `Xs2SellerListingTransformer::lookupCategoryId`:

1. **Mapped seat IDs** — if category mapping has `stadium_seat_id` / details / candidate scores, try numeric ID match in dropdown.
2. **Name match** — normalized match of XS2 `category_name` against dropdown `category[].category_name`.
3. **Mapping hints** — stadium seat names from confirmed mapping details or ranked candidates.
4. **Fail** — throw `ListingTransformationException` with available SB categories; never call SB API.

## Publish gates (before transform)

1. Event mapping status is `mapped` or `created` with valid `m_id`.
2. Event is sellable (`Xs2Event::isSellable()`).
3. Mapping status allows publish (`canAutoPublish` / `isManualPublishable`).
4. XS2 `category_name` is non-empty.
5. Price and currency are present.

## Blocked vs allowed examples

**Blocked** — missing category resolution:

```
XS2 category_name: "Matchday Premium"
SB dropdown categories: ["Away", "Home"]
→ ListingTransformationException: Could not resolve XS2 category ...
→ No HTTP call to SB
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
