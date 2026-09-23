# 2. Data Model

One Postgres instance, one schema per module: `hub`, `ingest`, `bench`, `peer_pool`, `strategy`, `audit`.
Every tenant-owned table carries `org_id`. **Row-level security** policies key off a per-request
`app.current_org_id` setting; the application DB role is non-superuser and cannot bypass RLS.
Tools share data through APIs, never a database, so the Web tool's DB engine is irrelevant.

## 2.1 `hub`: identity and shared entities

| Table | Notes |
|---|---|
| `orgs` | The subscriber (tenant). |
| `venues` | `org_id`, name, address, timezone, `market_id`, segment, cuisine, size band, business-day cutoff, GST basis default. |
| `users`, `memberships` | A user belongs to orgs with a role: owner, manager or viewer. |
| `external_identities` | (tool, external user id) to link and migrate Web tool users. |
| `oauth_*` | Passport clients, tokens and scopes (per tool). |
| `geo_areas` | Hierarchy: market -> region -> state -> country. Markets are ABS suburbs/localities; regions are ABS Greater Capital City areas (Dianella -> Greater Perth -> WA -> AU). NZ later is another branch. |
| `competitor_sets` | `org_id`, name, `tools text[]` (e.g. `{revenue,web}` shared, `{web}` Web only). |
| `competitor_set_members` | Name, domain, place/address, and `matched_venue_id` (nullable; resolved by domain, address, place ID, ABN if collected). Never exposed to subscribers. |
| `venue_competitor_sets` | Which sets a venue uses. |
| `contribution_consent` | Org-level, timestamped, versioned opt-in to the peer pool and to competitor-set aggregates. |

## 2.2 `ingest`

| Table | Notes |
|---|---|
| `pos_connections` | Venue, provider, method (`api` / `intermediary` / `file` / `manual`), status, sync cursor, credentials as KMS envelope-encrypted blob. |
| `ingestion_runs` | Status, counts, errors, timings. |
| `source_snapshots` | Pointer and hash into encrypted object storage for a PII-stripped snapshot; 90-day retention (proposed default). |
| `sales_daily` | Venue, business date, `revenue_cents`, `gst_inclusive`, nullable `tx_count`, source, run id, `revised_at`. Unique on (venue, date), so re-syncs upsert idempotently and POS revisions are tracked. |

Granularity is deliberately coarse: it supports the MVP metrics (revenue and growth) and keeps
CSV uploads and weak POS exports viable. Hourly, channel and category tables are later additions.

## 2.3 `bench`

- **Daily venue metrics (base):** one row per venue per day, rebuilt incrementally when `sales_daily`
  changes. Includes same-weekday WoW and YoY comparisons, rolling 7- and 28-day totals and a
  seasonality-adjusted growth index. Day-over-day alone is not used because it compares unlike weekdays.
- **Weekly and monthly** are rollups (on read or materialised views), not separate sources of truth.

## 2.4 `peer_pool`: no org or venue IDs anywhere

`cohort_aggregates`: `dimension` (market / cuisine / segment cohort, or competitor_set), geo area, segment,
cuisine (NULL means "all"), or competitor-set snapshot id, period (day, and rollups), contributor
count, and percentiles/averages of growth and *indexed* measures. Absolute revenue aggregates are
published only where the rules below allow.

### Benchmark rules (hard)

1. **Minimum 5 participants.** Each resolved cohort needs at least 5 opted-in venues with data for the
   period. Below 5, no aggregate is computed or stored, and the API returns *unavailable*. Enforced
   by a DB `CHECK` on the peer-pool tables, in addition to application code.
2. **Distinctness.** Market/segment/cuisine cohorts also require at least 2 distinct orgs, and no single
   venue may dominate the average. The competitor-set view requires **5 distinct orgs**, excluding the viewer's own org.
3. **Progressive filters.** Anything the subscriber has not selected stays at its widest scope:
   geography defaults to the venue's region (e.g. Greater Perth); segment and cuisine default to all.

   | Selection | Cohort |
   |---|---|
   | Nothing | All participants in the region |
   | Cuisine only | That cuisine across the region |
   | Segment only | That segment across the region |
   | Market only | All venues in the market |
   | Market + segment and/or cuisine | Venues matching every selected filter |

4. **No silent widening.** If a selection yields fewer than 5, the result is *unavailable*; the API
   does not fall back to a broader cohort. The UI shows a generic "try a broader selection" hint with no counts.
5. **Nested-cohort differencing protection.** A cohort is published only if every larger cohort that contains it
   has either the same participants or at least 5 more. Otherwise the smaller one is suppressed.
   (Example: market n=5 inside region n=6 would let the sixth venue's value be derived.)
6. **Competitor-set view:** averages only the set's named competitors who are opted-in subscribers,
   computed on a **snapshot** of matched membership. Membership edits are rate-limited (proposed: 30-day lock on
   the matched composition), and removals that would drop the matched count below 5 are blocked, to stop differencing.
7. **Daily grain:** thresholds apply per cohort per day. There is no fallback to a rolling window.

## 2.5 `strategy`

| Table | Notes |
|---|---|
| `insights` | Findings JSON per venue per period and its hash. |
| `strategy_plans` | `source_tool`, schema version, plan JSON, findings hash, model tier, tokens, cost, status. Also receives plans posted by the Web tool. |
| `ai_usage_ledger` | Per-org monthly token/cost use and budget. |

## 2.6 `audit`

`audit_log` is append-only (actor, org, action, entity, IP, timestamp). The app's DB role can INSERT but
not UPDATE or DELETE. It is shipped to immutable storage.

## 2.7 Lifecycle

- Deleting a venue or org cascades through tenant tables and destroys the org's data key (crypto-shredding).
- The peer pool holds only aggregates, so it is unaffected by deletions. Aggregates are recomputed on schedule.
- Source snapshots expire after 90 days; canonical `sales_daily` persists while the subscription is active.
