# Plan E: Square sales feed, covers, and food/drinks revenue (design)

**Goal:** venues on Square stop uploading CSVs: their sales arrive nightly, split into **food** and **drinks**, and
each day carries **covers**. With those three figures the Revenue app shows real average spend per cover and the
food/drinks mix, the first of the mock-up's preview cards to become real (ACI, per cover).

Builds on Plan C (sales tables, metrics, uploads), Plan D (the Revenue app) and 03-integrations.md §3.1 (connector
interface, sync behaviour, credential handling).

## 1. Decisions (asked 2026-10-01)

| # | Decision |
|---|---|
| E1 | **Square is connected by the owner** (OAuth, owner with MFA) and synced nightly, with a backfill on connect and a re-pull of recent days. |
| E2 | **Covers come from the venue for now:** a daily covers entry in the app and an optional covers column in CSV uploads. **Booking platforms (OpenTable, NowBookIt and similar) are the next covers source** (Plan F): the covers model records each day's source so a booking feed slots in without schema changes. Square for Restaurants lets staff record a guest count (covers) on table checks, but the public Orders API has not exposed it (Square developer forums); the newer Reporting API is checked for a covers measure in the sandbox (Task 13). If Square exposes it, the sync writes it as covers source `pos`. |
| E3 | **Food vs drinks: the owner maps Square categories** to Food, Drinks or Other once, with guesses pre-filled from names. New, unmapped categories count as Other and are flagged until mapped. |
| E4 | **Revenue is net sales including GST**: after discounts and refunds, without tips; service charges count as Other. Same basis as today's `revenue_cents` (GST-inclusive). |

## 2. Data model

### 2.1 Sales by day (existing `sales_daily`, extended)

New nullable columns, all set together when a source knows the split:

| Column | Meaning |
|---|---|
| `food_cents`, `drinks_cents`, `other_cents` | Net sales incl. GST by kind. When present, `food + drinks + other = revenue_cents` (CHECK). |

`tx_count` stays (Square fills it with completed orders). Revisions (`sales_daily_revisions`) record the new
columns too. RLS and grants are unchanged.

### 2.1a Covers by day (new, `daily_covers`)

`(org_id, venue_id, business_date, covers, source ∈ manual|upload|pos|booking, updated_by, updated_at)`. Covers are kept
apart from sales so they can be entered before or without sales for that day, and a sales sync never touches them.
Metrics join the two by day. Changes are kept in `daily_covers_revisions`.

### 2.2 Square categories by day (new, `sales_daily_categories`)

`(org_id, venue_id, business_date, source, category_key, category_name, net_cents, quantity)`: one row per category
per day as Square reported it. `category_key` is the Square category id, or `uncategorised` / `service_charges`.
Keeping these lets the app re-derive food/drinks/other when the owner changes the mapping, without calling Square
again. RLS-scoped like `sales_daily`.

### 2.3 Category mapping (new, `category_mappings`)

`(org_id, venue_id, source, category_key, kind ∈ food|drinks|other, mapped_by, mapped_at)`. Unmapped keys count as
Other. Changing a mapping recomputes `food/drinks/other_cents` for every day of that venue (one transaction) and
then the metrics (`RecomputeVenue`).

### 2.4 Covers precedence

One covers value per day (`daily_covers`). Sources rank: the venue's own entry (`manual` or `upload`) > the POS's
guest counts (`pos`, Square when available) > a booking feed (`booking`). A source writes or clears a day only when
it ranks at least as high as the figure already there, so a booking feed never overwrites a manual or POS figure.
Edits are audited.

### 2.5 Square connections (new, `pos_connections`)

Per org: `provider = 'square'`, the merchant id, encrypted tokens, scopes, `status` (connected, needs_reauth,
disconnected), `last_synced_at`, `last_error`, and per-venue location links (`pos_location_links`: Square location
id -> venue, one-to-one). Tokens are envelope-encrypted with the org's data key (the existing KMS driver), decrypted
only inside sync jobs, never logged or returned by the API.

## 3. Square integration

- **OAuth** (authorization code with the application secret, server side). The `state` is sealed with the app key
  (who asked, which org, a nonce, 10 minutes) and works once; it does not rely on the browser session, because
  Square returns the owner to the Hub's host, not the app's. At the callback the initiator must still be an owner
  with MFA. Scopes:
  `MERCHANT_PROFILE_READ` (locations), `ORDERS_READ`, `ITEMS_READ` (categories). Read-only. Access tokens expire
  (Square: 30 days); the sync refreshes them ahead of expiry; a failed refresh marks the connection `needs_reauth`
  and the app asks the owner to reconnect. **Disconnect** revokes the token at Square and deletes it here.
- **Locations:** after connecting, the owner links each Square location to a venue (or ignores it).
- **Sync job** (queue worker, per linked location):
  - Window: on connect, backfill up to 24 months; nightly, re-pull the last 7 business days (POS edits, late refunds).
  - `SearchOrders` for `COMPLETED` orders closed in the window (paged by cursor), assigned to business days with the
    venue's time zone and trading-day cutoff (Plan C's rule).
  - Per line item: net amount incl. GST = line total after discounts (Square AU prices are tax-inclusive; verified
    against sandbox data before launch), keyed by the item's reporting category (looked up from the catalog,
    cached per sync); ad hoc items without a catalog link go to `uncategorised`. Service charges -> `service_charges`.
    Returns and refunds subtract from their category on the day they happen. Tips are ignored.
  - Writes `sales_daily_categories` for the window, derives food/drinks/other and `revenue_cents`, sets `tx_count`
    (completed orders), `source = 'pos'`, records revisions, then `RecomputeVenue`. Covers are untouched.
  - Idempotent: re-running a window gives the same rows.
  - Rate limits and errors: exponential backoff with jitter, a per-connection circuit breaker (5 consecutive
    failures -> paused, shown in the app), health on the Data sources screen.
- **Scheduler:** `pos:sync` nightly per connection (spread over the night), plus "Sync now" for owners (rate limited).
- **Uploads and Square together:** a venue linked to Square no longer accepts sales CSVs for synced days (the
  wizard explains); covers-only uploads still work.

## 4. Covers entry

- **Daily covers** in the app (owners and managers): a week grid with one number per day, pre-filled where known,
  saved per day (audited). Shows the source (entered, uploaded, booking).
- **CSV:** the upload mapping gains optional `covers`, `food` and `drinks` columns (food + drinks must not exceed the
  total; the remainder is Other). A file with only date + covers is allowed for Square venues.
- Booking feeds (Plan F) write `covers_source = 'booking'` through the same service, respecting §2.4.

## 5. Metrics and screens

- **Metrics:** daily series and totals gain `covers`, `food_cents`, `drinks_cents`; derived: **average spend per
  cover** (revenue / covers, over days with covers), food and drinks per cover, food/drinks mix %. Days without
  covers are left out of per-cover figures, never counted as zero.
- **Overview:** a real "Average spend per cover" card and a "Food and drinks" mix card replace their preview twins
  (the preview section keeps market comparisons). Average check per transaction stays.
- **Data sources screen:** connect Square, link locations, map categories (with guesses and a "new categories"
  badge), connection health, last sync, "Sync now", disconnect.
- **Covers screen:** the week grid (§4).

## 6. Security

- Owner + MFA to connect, disconnect, link locations or change mappings; managers can enter covers and see health.
- OAuth `state` and redirect URI fixed per environment; tokens encrypted at rest (org data key); a stolen database
  without KMS access yields no usable token. Least scopes (read-only).
- Square responses are parsed defensively; only aggregates are stored (no customer names, card data or line-item
  personal data). Snapshots of raw API responses are not kept.
- Webhooks (later) would be HMAC-verified (Square signature key).

## 7. What the user provides

- **Square Developer account** with an application: sandbox credentials now (application id, secret), production
  credentials before the pilot; the OAuth redirect URL is `https://hub.successmeter.tech/api/pos/square/callback`.
- **Booking platforms (Plan F):** OpenTable, NowBookIt and others typically require a partner application before API
  access; applying early avoids waiting later. Which platforms the pilot venues use decides the order.

## 8. Not in Plan E

Booking platform connectors (Plan F), other POS systems (Impos, OrderMate, Bepoz, Idealpos, H&L; discovery first),
Square webhooks (nightly pull is enough for the pilot), item-level analytics, labour/rostering data.
