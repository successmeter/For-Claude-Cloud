# Plan C Design: Sales Data In, Own Performance Out

**Date:** 2026-09-29
**Status:** Draft for review
**Scope:** the Revenue tool's backend for getting a venue's daily sales in by CSV upload and giving back its own
performance: daily metrics with weekly and monthly rollups, and own-history insights (trends and anomalies). This is
the rest of Phase 1's backend (06 §6.1) apart from the React app, which is Plan D.

Builds on Plan A (foundation: orgs, venues, users, RLS, envelope encryption, audit) and Plan B (tenant context in
transactions, `X-Hub-Org` tenant resolution, problem responses).

---

## 1. Decisions

| # | Decision | Source |
|---|---|---|
| C1 | Uploads carry **daily totals only**: one row per business day (date, revenue, optional transaction count). No transaction-level files, so the upload does not apply the business-day cutoff; the file's dates *are* business days. | Asked 2026-09-29 |
| C2 | **CSV only.** XLSX can be added later behind the same mapping and validation step. | Asked 2026-09-29 |
| C3 | Metrics and insights use **GST-inclusive** revenue. Each row keeps the figure as supplied plus its GST flag; an ex-GST figure is converted with x1.1. | Asked 2026-09-29 |
| C4 | An upload is **previewed, then committed**. The preview lists new, changed (old vs new) and unchanged days; committing overwrites changed days and records each revision. Days not in the file are left alone. | Asked 2026-09-29 |
| C5 | **One venue per upload.** The venue is chosen before uploading; files have no venue column. | Default (simplest; multi-venue files later if asked for) |
| C6 | Any row with a problem **blocks the commit**. The preview lists every problem by row number; the user fixes the file and uploads again. | Default (no half-imported files) |
| C7 | **No new Composer dependency for CSV.** PHP's own CSV parsing with strict size and row limits is enough for daily totals. | Default |
| C8 | Tables stay in the `public` schema like Plans A and B (per-module schemas remain a later refactor, Plan B design §8.6). | Carried |

## 2. What a user can do at the end of Plan C (through the API)

1. Create a venue (name, address, time zone, segment, cuisine, GST default).
2. Download the CSV template.
3. Inspect a CSV for a venue. The API detects the columns and date format and proposes a mapping; nothing is stored.
4. Upload the same file with the confirmed mapping (date column and format, revenue column, optional
   transaction-count column, whether revenue includes GST). The API validates every row and returns the preview.
   Changing the mapping means uploading again with the new one; the browser still has the file.
5. Commit the upload, or discard it.
6. Read the venue's overview: latest business day's revenue, 7- and 28-day totals with same-weekday week-on-week and
   year-on-year changes, the daily series, flagged anomalies and a data-freshness indicator.
7. Read metrics by day, week or month for a date range, and the latest insights.

## 3. Data model

All tables carry `org_id` with an RLS policy in the Plan A pattern; grants come from Plan B's default privileges.

### 3.1 `venues` (exists; API added)

No schema change. Plan C adds the first-party API for it (§5.1). `market_id` stays optional; resolving it from an
address is onboarding work for Plan D (Places autocomplete, 04 §4.3).

### 3.2 `ingestion_runs`

One row per upload (and later per POS sync, so Phase 2 reuses it).

| Column | Notes |
|---|---|
| `id` uuid, `org_id`, `venue_id` | |
| `method` | `upload` now; `api` / `intermediary` in Phase 2 |
| `status` | `previewed` -> `committed`, or `discarded` / `expired` |
| `created_by` | user id |
| `file_sha256`, `file_bytes`, `row_count` | of the uploaded file; the file itself is not kept (§3.4) |
| `mapping` jsonb | the confirmed mapping (column names, date format, GST flag) |
| `summary` jsonb | counts: new, changed, unchanged, problems; first and last date |
| `problems` jsonb | up to 200 `{row, column, code}` entries (codes, not echoed cell content) |
| `basis_revision` bigint | the venue's latest `sales_daily` revision id when the preview was made (§4.4) |
| `created_at`, `committed_at`, `expires_at` | an uncommitted run expires 24 h after upload |

### 3.3 `ingestion_run_rows`

The parsed, normalised rows of a previewed run, so the commit applies exactly what was previewed. Deleted when the
run is committed, discarded or expired.

`run_id`, `org_id`, `business_date`, `revenue_cents` (as supplied), `gst_inclusive`, `tx_count` (nullable),
`change` (`new` / `changed` / `unchanged`). Unique on (`run_id`, `business_date`).

### 3.4 `source_snapshots`

What 02 §2.2 calls the PII-stripped snapshot. For an upload it is a **canonical CSV of the mapped columns only**
(date, revenue, GST flag, transaction count), built from the parsed rows. The raw file is parsed in memory and never
stored, so columns the mapping did not select (which could hold staff names or notes) are never kept.

`id`, `org_id`, `run_id`, `path` (on the `snapshots` disk), `sha256`, `bytes`, `created_at`, `expires_at`
(90 days, 07 §7.2). The file is envelope-encrypted with the org's data key (`EnvelopeEncryptor`) before it is
written. A scheduled `ingest:prune-snapshots` deletes expired files and rows; deleting an org already destroys its
data key (crypto-shredding).

### 3.5 `sales_daily`

As 02 §2.2, with the GST decision made concrete:

| Column | Notes |
|---|---|
| `org_id`, `venue_id`, `business_date` | unique on (`venue_id`, `business_date`) |
| `revenue_cents` bigint | as supplied, >= 0 |
| `gst_inclusive` boolean | as supplied |
| `revenue_inc_gst_cents` bigint | **generated**: `revenue_cents` if inclusive, else `round(revenue_cents * 1.1)` |
| `tx_count` integer null | >= 0 |
| `source` | `upload` (later `pos`, `intermediary`) |
| `ingestion_run_id` | the run that last wrote the row |
| `revision` bigint | from a sequence; bumps on every insert or change (§4.4) |
| `created_at`, `revised_at` | |

x1.1 assumes every sale in the day was taxable, which holds for most hospitality takings; a venue with GST-free sales
should upload GST-inclusive figures. The preview says which basis the file is read as.

### 3.6 `sales_daily_revisions`

Append-only history of changes: `org_id`, `venue_id`, `business_date`, old and new `revenue_cents`,
`gst_inclusive` and `tx_count`, `ingestion_run_id`, `revised_at`. Written in the same statement as the change.
Answers "who changed last March's figures, and from what".

### 3.7 `daily_venue_metrics`

One row per venue per business date that has sales (02 §2.3). All money is GST-inclusive cents.

| Column | Definition |
|---|---|
| `revenue_cents` | the day |
| `same_weekday_last_week_cents` | the day 7 days earlier (null if missing) |
| `same_weekday_last_year_cents` | the day **364** days earlier: same weekday, 52 weeks back (null if missing) |
| `rolling_7_cents`, `rolling_28_cents` | the 7 and 28 days ending on the day; **null unless every day is present** |
| `rolling_7_prev_cents`, `rolling_28_prev_cents` | the windows immediately before (same weekdays, since 7 and 28 are whole weeks) |
| `rolling_7_ly_cents`, `rolling_28_ly_cents` | the same windows 364 days earlier |
| `growth_index` numeric | `100 x rolling_28 / rolling_28_ly` (null without last year): 28-day year-on-year growth, which is seasonality-adjusted because it compares the same weeks of the year |

Percent changes (week-on-week, year-on-year) are computed on read from these columns, so a rounding rule lives in one
place (§5.3).

A **missing day is "no data", never zero.** A venue closed on Mondays uploads `0` for Mondays; a gap in the data
leaves the rolling windows that cover it null rather than showing a misleadingly low total.

Rebuild: after a commit, one SQL statement (window functions over the venue's `sales_daily`) upserts metrics from the
earliest changed date to the venue's latest date. Changing a day affects later rows for up to 392 days (364 + 28), and
a venue has at most a few years of daily rows, so this is milliseconds. It runs **inside the commit transaction**,
so the overview never shows new sales with old metrics. When POS sync arrives (Phase 2) the same statement runs from a
tenant-scoped job.

### 3.8 Weekly and monthly rollups

Computed on read from `daily_venue_metrics` (02 §2.3: rollups, not sources of truth). Weeks are ISO weeks starting
Monday. Each rollup returns `revenue_cents`, `days_with_data` and `days_in_period`, plus the same period last year
(the 52-weeks-back week; the same calendar month). A period with missing days is returned with its coverage so the UI
can mark it incomplete rather than compare it.

### 3.9 `insights`

As 02 §2.5: `org_id`, `venue_id`, `as_of` (business date), `findings` jsonb, `findings_hash` (sha256 of the canonical
JSON; Phase 4's AI cache keys on it), `rules_version`, `created_at`. Unique on (`venue_id`, `as_of`). Regenerated in the
commit transaction after the metrics rebuild, for the venue's latest date. History is kept.

## 4. Upload pipeline

### 4.1 Receiving the file

- `multipart/form-data`, field `file`. Limits: **2 MB** and **5,000 data rows** (13 years of days). Larger is refused
  before parsing (413 / 422).
- Accepted if the extension is `.csv` and the content sniffs as text; anything else 415.
- **Malware scan** through an `UploadScanner` interface before parsing. Production driver: ClamAV over the clamd
  socket. A `NullUploadScanner` for local and testing refuses to construct in any other environment, like
  `LocalFileKmsDriver`. An infected file is refused and audit-logged; nothing is stored.
- Encoding: UTF-8 with or without BOM; invalid UTF-8 is refused with a clear problem (the template and most POS
  exports are UTF-8; a Windows-1252 file is asked to be re-saved).
- Delimiter detection among comma, semicolon and tab from the header line.
- Rate limit: 20 uploads per user per hour (`uploads` limiter).

### 4.2 Detecting the mapping (inspect)

`inspect` parses the file under the same limits and returns the header, the first 5 rows (to the user who sent them;
nothing is stored or logged) and a proposed mapping. From the header and the rows the API proposes:

- **Date column:** header matches `date`, `business date`, `trading date`, `day` (case-insensitive), or the first
  column whose values all parse as dates.
- **Date format:** one of `YYYY-MM-DD`, `DD/MM/YYYY`, `D/M/YYYY`, `DD-MM-YYYY`, `DD.MM.YYYY`. Proposed only when
  exactly one format parses every value; if the file is ambiguous (e.g. only days <= 12) the user must choose.
  US month-first dates are not offered.
- **Revenue column:** `revenue`, `sales`, `net sales`, `gross sales`, `total`, `takings`, `amount`.
- **Transaction count column (optional):** `transactions`, `tx`, `orders`, `covers`, `receipts`, `count`.
- **GST:** defaults to the venue's `gst_inclusive_default`; a header containing `ex gst` / `excl` / `inc gst` / `incl`
  overrides the default in the proposal. The user always confirms it.

### 4.3 Validating (preview)

Every row is checked against the confirmed mapping. Problem codes (never echoing cell content back):

| Code | Meaning |
|---|---|
| `date_unparseable` | does not match the chosen format |
| `date_in_future` | after today in the venue's time zone |
| `date_too_old` | before 2015-01-01 |
| `date_duplicate` | the same date appears twice in the file |
| `revenue_unparseable` | not a number after removing `$`, spaces and thousands separators (`1,234.50`; `(12.00)` is refused) |
| `revenue_negative` | below zero |
| `revenue_too_large` | above $10,000,000 for one day (catches cents-vs-dollars and column mix-ups) |
| `tx_count_invalid` | not a whole number >= 0 |

Amounts are parsed as decimal strings into integer cents (no floats). Blank lines are skipped; a row with only the
date filled is `revenue_unparseable`.

Each valid row is compared with `sales_daily` for the venue and marked `new`, `changed` or `unchanged`. The preview
response returns the counts, the first and last date, all problems (up to 200), and the changed days with old and new
values (paged).

### 4.4 Commit

- Refused with 409 `preview_has_problems` if the preview has problems (C6).
- Refused with 409 `preview_stale` if the venue's sales changed since the preview (another upload committed): the
  venue's highest `sales_daily.revision` is compared with the run's `basis_revision`. The user previews again.
- Otherwise, in one transaction under tenant context: upsert the `new` and `changed` rows (writing revision history
  for changed ones), write the snapshot, rebuild metrics, regenerate insights, mark the run committed, delete its
  staged rows, and write the audit entry. `unchanged` rows are not touched, so their revision and `revised_at` stay.
- A venue-level advisory lock (`pg_advisory_xact_lock` on the venue id) serialises commits for one venue.

### 4.5 Template

`GET /api/uploads/template.csv`: header `date,revenue,transactions` and two example rows, with a note line explaining
the date format and GST. Any CSV the API generates escapes cells starting with `=`, `+`, `-`, `@`, tab or carriage
return (CSV formula injection).

## 5. API (first-party, for the React app)

Under `/api`, Sanctum cookie session (`auth:sanctum`), then the existing `tenant` middleware with the `X-Hub-Org`
header (Plan B: one tenant mechanism for every caller). Errors are RFC 9457 problems with Plan B's `Problem`.

### 5.1 Roles

| Action | owner | manager | viewer |
|---|---|---|---|
| Read venues, overview, metrics, insights | yes | yes | yes |
| Create and edit venues | yes | yes | no |
| Upload, preview, commit, discard; list uploads | yes | yes | no (04 §4.1: viewers cannot see Data sources) |

Owners need MFA for the owner-only routes that exist (Plan B's `mfa.owner`); Plan C adds none that are owner-only.

### 5.2 Endpoints

| Method and path | Purpose |
|---|---|
| `GET /api/venues`, `POST /api/venues` | list, create |
| `GET /api/venues/{venue}`, `PATCH /api/venues/{venue}` | read, edit |
| `GET /api/uploads/template.csv` | the template |
| `POST /api/venues/{venue}/uploads/inspect` | file -> columns, first rows, proposed mapping (§4.2); stores nothing |
| `POST /api/venues/{venue}/uploads` | file + mapping -> a previewed run (§4.3) |
| `GET /api/venues/{venue}/uploads` | the venue's runs, newest first |
| `GET /api/uploads/{run}` | a run with its summary and problems |
| `GET /api/uploads/{run}/changes?page=` | changed days, old vs new |
| `POST /api/uploads/{run}/commit` | commit (§4.4) |
| `DELETE /api/uploads/{run}` | discard |
| `GET /api/venues/{venue}/overview` | §5.3 |
| `GET /api/venues/{venue}/metrics?grain=day\|week\|month&from=&to=` | series with comparisons and coverage |
| `GET /api/venues/{venue}/insights/latest` | the latest findings |

Money is integer cents with `"currency": "AUD"`. Dates are `YYYY-MM-DD` business dates.

### 5.3 Overview

- **Latest business day:** the venue's most recent date with data, and its revenue with same-weekday WoW and YoY.
- **7- and 28-day totals** ending on that date, with the previous window and last year's window, and percent changes
  rounded to one decimal place (null when either side is null).
- **Daily series:** the last 90 days, with last year's same weekday alongside.
- **Anomalies:** the anomaly findings among the latest insights (§6).
- **Freshness:** `fresh` if the latest date is yesterday or today in the venue's local business day (applying the
  cutoff to "now"), `behind` if 2-7 days old, `stale` beyond that, `none` without data. The overview never pretends
  missing days are zero.

## 6. Own-history insights (findings v1)

Findings are facts computed from the venue's own data, each with a stable id and every figure it states, so Phase 4's
AI plans can cite them (03 §3.2 traceability). Schema: `api/schemas/findings.v1.json` (JSON Schema 2020-12, validated in
tests). Thresholds live in `config/insights.php`.

| Type | Rule (defaults) |
|---|---|
| `trend_28d_yoy` | 28-day total vs the same 28 days last year; reported when both are complete. `material` when the change is >= 5% either way. |
| `trend_28d_vs_prior` | 28-day total vs the previous 28 days; `material` at >= 10%. |
| `weekly_streak` | 3 or more consecutive complete weeks, each below (or above) the previous week and the same week last year. |
| `anomaly_day` | a day in the last 28 whose revenue differs from its **same-weekday baseline** (median of the previous 8 same weekdays; at least 4 needed) by >= 30% **and** by a robust z-score (median absolute deviation) of >= 3.5. Direction `high` or `low`. |
| `best_worst_weekday` | over the last 12 complete weeks, the weekday with the highest and lowest median revenue and its share of the week. |
| `data_gap` | missing days inside the last 28 days (so the other findings' gaps are explained). |

Each finding: `id` (`f1`, `f2`, ... in a deterministic order), `type`, `material` (bool), `period` {from, to}, and the
figures (`value_cents`, `comparison_cents`, `change_pct`, ...). The set is empty rather than invented when there is
too little history; the overview says how much history is needed (28 days for trends vs prior, 364 + 28 for YoY).

## 7. Security

- RLS on every new table; tests prove another org's rows are invisible and unwritable (Plan A pattern).
- Upload safety per 05 §5.7 and §5.9: size, type and row limits, malware scan, in-memory parsing with no formula evaluation,
  formula-injection escaping on every CSV the API emits, problem codes instead of echoed content.
- Only mapped columns are kept (§3.4); snapshots are envelope-encrypted and expire after 90 days.
- Audit log: `venue.created`, `venue.updated`, `upload.received`, `upload.rejected_malware`, `upload.committed` (with
  counts and date range), `upload.discarded`.
- `ingestion_runs` of other users in the same org are visible to owners and managers (they share the venue's data).

## 8. Testing

- Feature tests for every endpoint: roles, tenant isolation, problem responses.
- CSV parser: BOM, semicolons and tabs, quoted fields with commas, `$1,234.50`, blank lines, invalid UTF-8, a formula
  in a cell, 5,001 rows, 2 MB + 1 byte, duplicate dates, ambiguous dates.
- Commit: new/changed/unchanged handling, revision history, `preview_stale` after a concurrent commit, problems block
  the commit, expiry of unfinished runs.
- Metrics: fixed fixtures with known answers, covering gaps (null rolling windows), zeros for closed days, a leap year
  (364-day YoY never crosses into the wrong weekday), and the rebuild range after an edit in the middle of the history.
- Insights: each rule with fixtures just under and just over its thresholds; findings validate against the schema;
  the hash is stable for the same data.
- RLS catalog test extended to the new tables.

## 9. Out of scope

POS connectors and the intermediary (Phase 2); XLSX; transaction-level files; the React screens (Plan D); benchmarks
and the peer pool (Phase 3); AI (Phase 4); `venue.updated` webhooks to other tools (the Web tool does not use venues
yet).

## 10. Open items raised by this design

1. **Peer-pool poisoning (05 §5.9 "decide in Phase 1").** Manual uploads are unverified. Plan C records `source` on
   every row, so Phase 3 can either count only POS-sourced venues toward thresholds or cap the manual share. The choice
   is needed before Phase 3's design, not for Plan C.
2. **ClamAV in production** needs a clamd service in the hosting plan (07 open question 4).
3. **x1.1 GST conversion** is approximate for venues with GST-free sales (§3.5). If that matters for a pilot venue,
   add a per-venue GST-free share later.
4. **Snapshot storage** uses a local disk in development; production needs the object-storage disk (S3 or equivalent,
   AU region) configured with the hosting decision.
