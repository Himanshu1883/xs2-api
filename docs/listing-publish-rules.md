# Listing Publish Rules (Seats Broker)

Any code path that publishes an XS2 ticket listing to Seats Broker (SB) **must** satisfy these rules. If any rule fails, **do not call** the SB Seller API (`POST /api/ticket/create` or update equivalents).

## Enforcement

| Layer | Class | Role |
|-------|-------|------|
| Pre-flight | `ListingPublishValidator` | Validates mapping, ticket fields, and transformed payload |
| Transform | `Xs2SellerListingTransformer` | Builds SB payload; always sends XS2 inventory category name |
| Job | `PushXs2TicketToSellerApi` | Runs validator → transform → validate payload → HTTP |
| Split | `SplitListingService` | Same validator + transformer gates for split listings |

## Required SB create payload fields

| Field | Type | Source |
|-------|------|--------|
| `match_id` | integer | Confirmed `EventMapping.m_id` |
| `ticket_category` | string | XS2 ticket/inventory `category_name` (never a mapped SB id) |
| `category_name` | string | Same XS2 inventory `category_name` |
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

SB create/update requires the `ticket_category` field (`"The ticket category field is required."` when omitted). Always populate **`ticket_category` and `category_name`** with the XS2 inventory category name. Do **not** send a mapped Seller API catalog id or local mapping dropdown id.

## Category resolution

Always publish with the XS2 ticket's inventory category name. Do not look up or require a Seller API `ticket_category` id. Category-mapping table rows and dropdown ids are not used for this field.

If the XS2 ticket has no category name, fail locally with a clear error and do not call SB.

## Publish gates (before transform)

1. Event mapping status is `mapped` or `created` with valid `m_id`.
2. Event is sellable (`Xs2Event::isSellable()`).
3. Mapping status allows publish (`canAutoPublish` / `isManualPublishable`).
4. XS2 `category_name` is non-empty.
5. Price and currency are present.

## Blocked vs allowed examples

**Allowed** — XS2 inventory name, even when SB dropdown has no match:

```
XS2 category_name: "Corner"
SB dropdown categories: ["Away", "Home"]
→ ticket_category: "Corner"
→ category_name: "Corner"
→ createListing(...) proceeds
```

**Allowed** — XS2 inventory name, even when a dropdown id exists:

```
XS2 category_name: "Longside Upper"
SB dropdown: [{ id: 4, category_name: "Longside Upper" }]
→ ticket_category: "Longside Upper"
→ category_name: "Longside Upper"
→ createListing(...) proceeds
```

**Blocked** — missing XS2 inventory category name:

```
XS2 category_name: ""
→ ListingTransformationException: XS2 inventory category name is missing.
→ no Seller API HTTP call
```

## Admin split / quantity rules

Separate from SB contract rules: `ListingPublishRuleService` controls stock-based split vs single-listing publish plans. Those rules choose *how many* listings to create, not whether SB field requirements are met.
