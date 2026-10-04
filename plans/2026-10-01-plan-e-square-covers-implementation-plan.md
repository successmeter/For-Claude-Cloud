# Plan E: Square sales feed, covers and food/drinks (implementation plan)

**Design:** `plans/2026-10-01-plan-e-square-covers-design.md` (decisions E1-E4).

Every task is test-first: write the test, see it fail for the stated reason, implement, see it pass, run the whole
suite, commit. Square is never called in tests: the client takes an HTTP handler, and tests replay recorded-shape
responses. A sandbox run against real Square needs the user's sandbox credentials (Task 13).

| Track | Repo / branch | Tasks |
|---|---|---|
| A (API) | For-Claude-Cloud, `claude/sleepy-fermat-jc39ms` (restarted from `main`) | 1-9 |
| F (app) | performance-benchmarking, `claude/plan-e-app` from `claude/plan-d-app` (Plan D is not merged yet) | 10-12 |
| Check | both | 13 |

## Track A: API

### Task 1: Data model
- Migration: `sales_daily` + `sales_daily_revisions` gain `food_cents`, `drinks_cents`, `other_cents` (CHECK: all or
  none, summing to `revenue_cents`). New `daily_covers` (+ `daily_covers_revisions`), `sales_daily_categories` and
  `category_mappings`; RLS forced, grants, no DELETE where history matters.
- Tests: constraints, RLS isolation, revisions capture the new columns.

### Task 2: Covers API
- `GET /api/venues/{venue}/covers?from&to` (everyone) -> days with covers and source; `PUT .../covers` (owners,
  managers) with `{days: [{date, covers|null}]}` (null clears), max 62 days, not in the future. Stored in
  `daily_covers`; precedence per design §2.4 (`CoversService`, also used by uploads and booking feeds). Audit.
- Tests: role matrix, precedence (booking never overwrites manual), validation, RLS, metrics recomputed.

### Task 3: Uploads with covers, food and drinks
- Mapping gains optional `covers_column`, `food_column`, `drinks_column`; detector proposes them from headers
  (covers, pax, guests; food; beverage, drinks, bar). Problems: `covers_invalid`, `split_exceeds_total`.
- Covers-only files (date + covers) are accepted (revenue unchanged); a Square-linked venue accepts only those.
- Tests: proposals, validation, preview counts, commit writes the columns and `covers_source = 'upload'`.

### Task 4: Metrics
- Daily series + overview carry `covers`, `food_cents`, `drinks_cents`; overview adds a 28-day `per_cover` block
  (revenue, food, drinks per cover over days with covers; mix %). Insights unchanged.
- Tests: per-cover math skips days without covers; nulls stay null.

### Task 5: Square connection and OAuth
- `pos_connections`, `pos_location_links` tables (RLS). Config `services.square` (env `SQUARE_APPLICATION_ID`,
  `SQUARE_APPLICATION_SECRET`, `SQUARE_ENVIRONMENT` sandbox|production).
- `POST /api/pos/square/connect` (owner + MFA) -> authorize URL with `state` (stored in session, single use);
  `GET /api/pos/square/callback` exchanges the code, stores tokens encrypted with the org's data key, redirects to the
  app's Data sources page. `DELETE /api/pos/square` revokes and deletes. `GET /api/pos` -> connection status (no
  tokens).
- Tests: state mismatch/replay refused, tokens never in responses or logs, encryption at rest, revoke on disconnect,
  role/MFA gates.

### Task 6: Square client, locations and links
- `SquareClient` (Guzzle, injectable handler): locations, catalog categories/items, search orders (paged), token
  refresh. Retries with backoff on 429/5xx; typed errors (auth vs transient).
- `GET /api/pos/square/locations`, `PUT /api/pos/square/locations/{id}` link/unlink a venue (owner + MFA).
- Tests: paging, retry, refresh-before-expiry, auth failure -> `needs_reauth`.

### Task 7: Categories and mapping
- Category discovery from the catalog (and from synced data); guesses from names (wine, beer, spirits, cocktails,
  coffee, beverages, drinks, bar -> drinks; food words -> food; otherwise blank).
- `GET /api/venues/{venue}/category-mappings` (categories seen, with net sales last 28 days, kind or null, guess);
  `PUT` (owner + MFA) saves kinds; recompute food/drinks/other for all days, then metrics.
- Tests: guesses, unmapped -> other, remap recomputes history, audit.

### Task 8: Sync
- `SyncSquareLocation` job: window rules (backfill 24 months on first sync; else last 7 business days), orders ->
  per-category day totals (business-day cutoff + time zone; returns on their day; service charges; ad hoc items),
  writes categories + `sales_daily`, revisions, `RecomputeVenue` (covers untouched).
- `pos:sync` scheduled nightly; `POST /api/pos/square/sync` (owner/manager, 1 per 10 minutes); circuit breaker after 5
  failures; health on `GET /api/pos`.
- Tests: fixture orders -> exact day totals (cutoff, refunds, discounts, service charges, ad hoc), idempotence,
  re-pull window, breaker, uploads refused for synced days.

### Task 9: Config, infra and docs
- Hub secret keys `SQUARE_APPLICATION_SECRET` (optional: the stack references it only when `squareApplicationId` is
  set in cdk.json), env `SQUARE_APPLICATION_ID`, `SQUARE_ENVIRONMENT`; README and hosting guide sections.

## Track F: the Revenue app

### Task 10: Covers screen
- `/covers`: week grid (prev/next week), one input per day, source labels, save; owners and managers edit, viewers read.

### Task 11: Data sources
- `/data` gains "Connect Square" (owner), connection health, last sync, Sync now, disconnect; location linking;
  category mapping table with guesses and a "new" badge. Upload wizard: covers/food/drinks columns.

### Task 12: Overview
- Real "Average spend per cover" and "Food and drinks" cards (hidden without data); their preview twins are removed
  from the preview section (the market comparison cards stay preview).

## Task 13: Sandbox check and docs
- Square guest counts: in the sandbox, check the Reporting API schema (and order payloads from Square for
  Restaurants checks) for a covers / guest count field. If present, the sync writes daily covers with source `pos`
  through `CoversService` (ranked below the venue's own entry, above booking feeds).
- With sandbox credentials: connect a Square sandbox seller, create test orders, sync, compare day totals with
  Square's sales summary; Playwright journey extended (connect is stubbed in CI). Update 04-ui, 07, READMEs.
