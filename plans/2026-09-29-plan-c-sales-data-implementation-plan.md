# Sales Data In, Own Performance Out (Plan C) Implementation Plan

> **For agentic workers:** Execute task by task, in order. Steps use checkbox (`- [ ]`) syntax for tracking.
> Every task is test-first: write the test, see it fail for the stated reason, implement, see it pass, run the whole
> suite, commit.

**Goal:** A venue's owner or manager can upload daily sales by CSV (inspect, preview, commit), and anyone in the org can
read the venue's overview, metrics by day/week/month and own-history insights.

**Spec:** `plans/2026-09-29-plan-c-sales-data-design.md` (decisions C1-C8). Also `02-data-model.md` §2.2-2.3 and
§2.5, `03-integrations.md` §3.1 (manual upload), `04-ui.md` §4.3 (Overview), `05-security.md` §5.7 and §5.9.

**Repository:** `successmeter/For-Claude-Cloud`, app in `api/`, branch `claude/plan-c-sales-data` (branched from Plan
B's branch; rebase or merge `main` once Plan B merges).

**Tracks:** D (data foundation, Tasks 1-3), M (metrics and insights, Tasks 4-7), U (upload pipeline, Tasks 8-14),
R (read API, Tasks 15-17), F (finish, Tasks 18-19). M does not depend on U: metrics are tested by writing
`sales_daily` rows directly, and the commit (Task 12) then calls into M.

## Global Constraints

- Everything in Plans A and B's Global Constraints holds: RLS (`ENABLE` + `FORCE`) and a policy on every table with
  `org_id`; tenant context only through `TenantContext::run()` (transaction-local); `app_user` gets `DELETE` only per
  table with a comment saying why.
- First-party routes are `auth:sanctum` then `tenant` (`X-Hub-Org`), in `routes/api.php`. Errors are RFC 9457 problems
  via `App\Hub\Http\Problem` (move it to `App\Http\Problem` in Task 1 if it reads better; keep one class).
- Money is integer cents everywhere; amounts are parsed from decimal strings, never through floats. Metric money is
  GST-inclusive (`revenue_inc_gst_cents`).
- A missing day is never treated as zero.
- The raw uploaded file is never written to disk, a log, the database or an error message.
- No new Composer runtime dependency (C7).
- Tests run on Postgres (`testing` database) like the rest of the suite; `Http::preventStrayRequests` stays on.

---

## Track D: data foundation

### Task 1: First-party venue API

**Files:** `routes/api.php`, `app/Http/Controllers/VenueController.php`, `app/Http/Requests/VenueRequest.php`,
`tests/Feature/Venues/VenueApiTest.php`, `tests/Support/SpaRequests.php` (helper: acting as a user with a Sanctum
session and the `X-Hub-Org` header).

- Routes: `GET/POST /api/venues`, `GET/PATCH /api/venues/{venue}` under `auth:sanctum` + `tenant`.
- Writes need role owner or manager (`VenuePolicy::update`; add `create`). Viewer -> 403 problem.
- Fields: `name` (required, <= 255), `address`, `timezone` (valid IANA, default `Australia/Perth`), `segment`
  (enum `restaurant|cafe|bar|other`), `cuisine`, `business_day_cutoff` (`HH:MM`, 00:00-06:00),
  `gst_inclusive_default` (bool). `market_id` is not writable here (Plan D resolves it).
- Response: `id`, the fields above, `created_at`. Audit `venue.created` / `venue.updated` with changed field names only.
- [ ] **Tests:** role matrix; another org's venue -> 404; invalid time zone / cutoff -> 422 problem; audit rows.
- [ ] **Commit** `feat: first-party venue API`

### Task 2: Sales tables

**Files:** one migration per table group, `app/Ingest/Models/{IngestionRun,IngestionRunRow,SourceSnapshot,SalesDaily,SalesDailyRevision}.php`,
`app/Bench/Models/DailyVenueMetric.php`, `app/Strategy/Models/Insight.php`, `tests/Feature/Ingest/SalesTablesRlsTest.php`.

- Columns exactly as design §3.2-3.9. `sales_daily.revenue_inc_gst_cents` is a stored generated column:
  `CASE WHEN gst_inclusive THEN revenue_cents ELSE round(revenue_cents * 1.1)::bigint END`.
- `sales_daily.revision` defaults to `nextval('sales_daily_revision_seq')`; updates set it explicitly (Task 12).
- Checks: `revenue_cents >= 0`, `tx_count >= 0`, `ingestion_runs.status IN (...)`, `method IN (...)`.
- Foreign keys cascade from `venues` (venue deletion removes its sales, metrics and insights).
- `DELETE` for `app_user`: `ingestion_run_rows` (staging is cleared), `source_snapshots` (pruning). Comment: the RLS
  policy limits both to the current org.
- `sales_daily_revisions` is append-only for `app_user` (no `UPDATE`/`DELETE`), like `audit_log`.
- [ ] **Tests:** each table invisible and unwritable across orgs; the generated column for both GST flags
  (`1000` ex -> `1100`; `999` ex -> `1099`); the unique (`venue_id`, `business_date`); revisions reject `UPDATE`;
  the Plan B catalog test passes with no allowlist additions.
- [ ] **Commit** `feat: sales, metrics and insights tables with RLS`

### Task 3: Snapshot disk and upload scanner

**Files:** `config/filesystems.php` (`snapshots` disk: local `storage/app/snapshots` in dev; env-driven),
`config/ingest.php` (limits, scanner driver, clamd socket), `app/Ingest/Scanning/{UploadScanner,NullUploadScanner,ClamAvUploadScanner,ScanResult}.php`,
binding in `AppServiceProvider`, `tests/Unit/Ingest/ClamAvUploadScannerTest.php`.

- `UploadScanner::scan(string $bytes): ScanResult` (`clean` / `infected(signature)` / `error`).
- `NullUploadScanner` throws in its constructor outside `local`/`testing` (same guard as `LocalFileKmsDriver`).
- `ClamAvUploadScanner` speaks clamd `zINSTREAM` over a unix or TCP socket (4-byte big-endian length chunks, zero
  terminator), with a connect/read timeout. A scanner `error` refuses the upload (fail closed).
- The socket is behind a small `ClamdConnection` interface so the test drives the protocol with an in-memory fake:
  `stream: OK`, `stream: Eicar-Signature FOUND`, `INSTREAM size limit exceeded. ERROR`, and a timeout.
- [ ] **Commit** `feat: snapshot disk and upload scanner`

## Track M: metrics and insights

### Task 4: Daily metrics builder

**Files:** `app/Bench/DailyMetricsBuilder.php`, `tests/Feature/Bench/DailyMetricsBuilderTest.php`,
`tests/Fixtures/sales/*.php` (small arrays of date => cents).

- `rebuild(string $venueId, CarbonImmutable $from): void`: one `INSERT ... SELECT ... ON CONFLICT (venue_id,
  business_date) DO UPDATE` over `sales_daily` for the venue, from `$from` to the venue's last date. Window values
  come from self-joins on `business_date - 7`, `- 364`, and ranged sums over `generate_series` days, so a missing day
  makes a window null (count of present days must equal the window length).
- Rows for dates that no longer have sales are deleted in the same call (not possible via upload today, but keeps the
  table honest).
- `growth_index` = `round(100.0 * rolling_28 / rolling_28_ly, 2)`; null if either is null or `rolling_28_ly = 0`.
- [ ] **Tests (known answers):** a constant 100/day series; a gap on one day nulls exactly the 7 and 28 windows that
  cover it; zeros count as present; YoY lines up on the same weekday across 29 Feb 2028; editing a day in the middle
  and rebuilding from it changes exactly the rows up to 392 days later; an ex-GST day enters metrics x1.1.
- [ ] **Commit** `feat: daily venue metrics builder`

### Task 5: Rollups

**Files:** `app/Bench/Rollups.php`, `tests/Feature/Bench/RollupsTest.php`.

- `weekly(venueId, from, to)` (ISO weeks, Monday start) and `monthly(venueId, from, to)`: `revenue_cents`,
  `days_with_data`, `days_in_period`, `last_year_cents` (week: 52 weeks back; month: same calendar month), and
  `last_year_days_with_data`. Percent change only when both periods are complete.
- `daily(venueId, from, to)`: rows from `daily_venue_metrics` with the comparison columns.
- Range limits: at most 3 years per request.
- [ ] **Tests:** a partial week and month report coverage and no change; a complete month vs last year; week 53 years.
- [ ] **Commit** `feat: weekly and monthly rollups`

### Task 6: Findings schema and insight rules

**Files:** `api/schemas/findings.v1.json`, `config/insights.php`, `app/Strategy/Insights/{InsightEngine,Finding}.php`,
`app/Strategy/Insights/Rules/{Trend28dYoy,Trend28dVsPrior,WeeklyStreak,AnomalyDay,BestWorstWeekday,DataGap}.php`,
`tests/Unit/Strategy/Insights/*RuleTest.php`, `tests/Feature/Strategy/InsightEngineTest.php`.

- Each rule reads `daily_venue_metrics` (and `sales_daily` for the anomaly baseline) up to `as_of` and returns zero or
  more `Finding`s. Rules and thresholds exactly as design §6; thresholds from config.
- Robust z-score: `0.6745 x (value - median) / MAD`; when `MAD = 0` the z-test is skipped and only the % rule applies.
- The engine orders findings (type, then period start) and numbers them `f1..fn`; canonical JSON (sorted keys, no
  whitespace) is hashed with sha256. `rules_version` = `1`.
- `generate(venueId, asOf)` upserts the `insights` row for (`venue_id`, `as_of`).
- [ ] **Tests:** each rule just under and just over its threshold; too little history -> no finding; findings validate
  against the schema (opis/json-schema, already a dev dependency); same data -> same hash; a changed day -> new hash.
- [ ] **Commit** `feat: own-history insight rules and findings v1`

### Task 7: Rebuild service

**Files:** `app/Bench/RecomputeVenue.php`, `tests/Feature/Bench/RecomputeVenueTest.php`.

- `run(venueId, earliestChangedDate)`: `DailyMetricsBuilder::rebuild` then `InsightEngine::generate` for the venue's
  latest date. Must be called inside tenant context (asserts `TenantContext::current()`).
- A `RecomputeVenueJob` (`TenantScopedJob`) wraps it for Phase 2; Plan C calls the service synchronously.
- [ ] **Commit** `feat: recompute venue metrics and insights`

## Track U: upload pipeline

### Task 8: CSV reader

**Files:** `app/Ingest/Csv/{CsvReader,CsvTable,CsvProblem}.php`, `tests/Unit/Ingest/Csv/CsvReaderTest.php`.

- `CsvReader::read(string $bytes): CsvTable` (header + rows of strings). Enforces `config('ingest.max_bytes')`
  (2 MB) and `max_rows` (5,000 data rows) with typed exceptions mapped to 413 / 422 problems.
- Strips a UTF-8 BOM; `mb_check_encoding` or refuse `encoding_not_utf8`. Detects the delimiter (comma, semicolon,
  tab) from the header line; parses with `str_getcsv` per logical record (quoted newlines supported via a
  `php://memory` stream and `fgetcsv`). Skips blank lines. Rows longer than the header are refused
  (`row_too_long`); shorter rows are padded.
- Header cells trimmed; empty or duplicate header names refused (`header_invalid`).
- [ ] **Tests:** BOM; `;` and tab; quoted commas and newlines; blank lines; invalid UTF-8; a Windows-1252 `é`;
  2 MB + 1 byte; 5,001 rows; duplicate headers; `=cmd|...` in a cell is returned as a plain string (never evaluated).
- [ ] **Commit** `feat: bounded CSV reader`

### Task 9: Parsers and mapping detection

**Files:** `app/Ingest/Upload/{DateFormats,MoneyParser,MappingDetector,Mapping}.php`, tests for each.

- `DateFormats`: the five formats in design §4.2; `parse(format, value): ?CarbonImmutable` is strict (no overflow,
  `31/02/2026` fails). `candidates(values)`: formats that parse every value.
- `MoneyParser::toCents(string): ?int`: trims, removes `$`, spaces and thousands commas; accepts up to 2 decimals;
  refuses parentheses, other currency symbols and more than 2 decimals. Uses string arithmetic (`bcmath` is not
  assumed; split on `.`).
- `MappingDetector::propose(CsvTable, Venue): array`: columns by the header lists in design §4.2, date format when
  exactly one candidate fits, GST from the header hint or the venue default, and `ambiguous` flags.
- `Mapping` is the validated request shape (`date_column`, `date_format`, `revenue_column`, `tx_count_column?`,
  `gst_inclusive`) with column names that must exist in the header.
- [ ] **Tests:** `1,234.50` -> 123450; `$0.5` -> 50; `12.345` refused; `(12.00)` refused; detection on the template,
  a Square-style export header, a semicolon file with `DD.MM.YYYY`, and an ambiguous file (all days <= 12).
- [ ] **Commit** `feat: date and money parsing, mapping detection`

### Task 10: Inspect endpoint

**Files:** `app/Http/Controllers/Uploads/InspectUploadController.php`, rate limiter `uploads` in
`AppServiceProvider`, `tests/Feature/Uploads/InspectUploadTest.php`.

- `POST /api/venues/{venue}/uploads/inspect` (owner/manager): size check, `.csv` extension and text sniff (415
  otherwise), scan, read, propose. Returns `columns`, `sample` (first 5 rows), `proposal`. Stores nothing, logs
  nothing about content.
- Infected -> 422 problem `upload_rejected` and audit `upload.rejected_malware` (signature name in `meta`, no content).
- Limiter `uploads`: 20 per user per hour, shared with Task 11.
- [ ] **Tests:** viewer 403; happy path; `.xlsx` 415; oversized 413; infected (fake scanner) 422 + audit; no row in
  any ingest table afterwards; the 21st call in an hour 429.
- [ ] **Commit** `feat: upload inspect endpoint`

### Task 11: Upload and preview

**Files:** `app/Ingest/Upload/UploadPreviewService.php`, `app/Http/Controllers/Uploads/UploadController.php@store`,
`tests/Feature/Uploads/UploadPreviewTest.php`.

- `POST /api/venues/{venue}/uploads` with `file` + mapping fields. Same checks as inspect, then every row is validated
  (problem codes in design §4.3; `date_in_future` uses the venue's time zone), compared with `sales_daily`
  (`new` / `changed` / `unchanged`) and staged in `ingestion_run_rows` in batches of 500.
- Creates the run: `status = previewed`, `mapping`, `summary`, `problems` (<= 200, with a `problems_truncated` flag),
  `basis_revision` = the venue's `max(sales_daily.revision)` (0 when none), `expires_at` = now + 24 h,
  `file_sha256`, `file_bytes`, `row_count`. Audit `upload.received`.
- Response 201: the run (as `GET /api/uploads/{run}` returns it).
- A mapping naming a missing column -> 422 problem before any row work.
- [ ] **Tests:** all-new file; a re-upload of the same file is all `unchanged`; a file changing 3 days lists them;
  every problem code; problems truncate at 200; ex-GST mapping stores `gst_inclusive = false`; staged rows match.
- [ ] **Commit** `feat: upload preview`

### Task 12: Commit

**Files:** `app/Ingest/Upload/CommitUpload.php`, `app/Ingest/Snapshots/SnapshotWriter.php`,
`UploadController@commit`, `tests/Feature/Uploads/CommitUploadTest.php`.

- `POST /api/uploads/{run}/commit` (owner/manager). In the request's tenant transaction:
  1. `pg_advisory_xact_lock(hashtext(venue_id))`; reload the run `FOR UPDATE`; it must be `previewed` and unexpired
     (else 409 `run_not_committable`).
  2. Problems -> 409 `preview_has_problems`. `max(revision)` != `basis_revision` -> 409 `preview_stale`.
  3. Insert `new` rows; for `changed` rows write `sales_daily_revisions` (old and new) and update with a new
     `nextval` revision, `revised_at = now()`, `ingestion_run_id`. Skip `unchanged`.
  4. `SnapshotWriter`: canonical CSV (`business_date,revenue_cents,gst_inclusive,tx_count`) of **all** the run's rows,
     envelope-encrypted with the org key, written to the `snapshots` disk under `{org}/{run}.csv.enc`; row in
     `source_snapshots` with sha256 of the plaintext and `expires_at` = now + 90 days.
  5. `RecomputeVenue::run(venue, earliest new-or-changed date)` (skipped when nothing changed).
  6. Run -> `committed`, `committed_at`; delete its staged rows; audit `upload.committed` with counts and date range.
- If the disk write fails the transaction rolls back; a file already written is deleted in a `finally` when the
  transaction did not commit (no orphan files).
- [ ] **Tests:** commit writes rows, revisions, snapshot (decrypts to the canonical CSV; the unmapped columns of the
  original file are absent), metrics and an insights row; problems -> 409; a second preview committed first makes the
  first `preview_stale`; committing twice -> 409; an expired run -> 409; a forced disk failure leaves no rows and no
  file; viewer 403.
- [ ] **Commit** `feat: commit uploads with revisions and snapshots`

### Task 13: Run management and template

**Files:** `UploadController@index/show/changes/destroy`, `app/Http/Controllers/Uploads/TemplateController.php`,
`app/Support/CsvWriter.php` (formula-injection escaping), tests.

- `GET /api/venues/{venue}/uploads` (newest first, paged 20), `GET /api/uploads/{run}`,
  `GET /api/uploads/{run}/changes?page=` (changed days, old vs new, 50 per page; only while `previewed`),
  `DELETE /api/uploads/{run}` (-> `discarded`, staged rows deleted, audit `upload.discarded`).
- `GET /api/uploads/template.csv` through `CsvWriter`, which prefixes `'` to cells starting with `=`, `+`, `-`, `@`,
  tab or CR.
- All owner/manager only.
- [ ] **Tests:** listing is per venue and per org; changes paging; discard then commit -> 409; the template parses
  with `CsvReader` and `MappingDetector` proposes a complete mapping for it; `CsvWriter` escaping cases.
- [ ] **Commit** `feat: upload listing, discard and CSV template`

### Task 14: Expiry and pruning

**Files:** `app/Console/Commands/{IngestExpireRuns,IngestPruneSnapshots}.php`, `routes/console.php`, tests.

- `ingest:expire-runs` (every 15 min): `previewed` runs past `expires_at` -> `expired`, staged rows deleted.
- `ingest:prune-snapshots` (daily): files then rows past `expires_at`.
- Both iterate orgs and work inside `TenantContext::run()` per org (RLS applies to commands too).
- [ ] **Tests:** only expired runs change; a snapshot file and row are removed; another org's data untouched.
- [ ] **Commit** `feat: expire stale uploads and prune snapshots`

## Track R: read API

### Task 15: Overview

**Files:** `app/Http/Controllers/Venues/OverviewController.php`, `app/Bench/Freshness.php`, tests.

- `GET /api/venues/{venue}/overview` (all roles), shape per design §5.3. Percent changes rounded to one decimal,
  null when a side is null or zero.
- `Freshness::for(venue, latestDate, now)`: the venue's current business date is `now` in its time zone minus the
  cutoff; `fresh` when latest >= that date - 1, `behind` up to 7 days, `stale` beyond, `none` without data.
- No data: 200 with `latest: null`, empty series and `freshness: none` (not 404).
- [ ] **Tests:** numbers against a fixture; freshness at 03:59 and 04:01 local with a 04:00 cutoff; a venue in
  `Australia/Sydney` vs `Australia/Perth`; empty venue.
- [ ] **Commit** `feat: venue overview`

### Task 16: Metrics endpoint

**Files:** `app/Http/Controllers/Venues/MetricsController.php`, tests.

- `GET /api/venues/{venue}/metrics?grain=day|week|month&from=&to=` (all roles) over `Rollups`. Defaults: last 90 days
  for `day`, 26 weeks, 12 months. Invalid grain or range -> 422 problem.
- [ ] **Tests:** each grain; range limit; viewer allowed; another org's venue 404.
- [ ] **Commit** `feat: metrics by day, week and month`

### Task 17: Insights endpoint

**Files:** `app/Http/Controllers/Venues/InsightsController.php`, tests.

- `GET /api/venues/{venue}/insights/latest` (all roles): `as_of`, `rules_version`, `findings_hash`, `findings`. None yet
  -> 200 with `findings: []` and `as_of: null`.
- [ ] **Tests:** response validates against `findings.v1.json`; empty venue.
- [ ] **Commit** `feat: latest insights endpoint`

## Track F: finish

### Task 18: End-to-end API test

**Files:** `tests/Feature/Uploads/SalesJourneyTest.php`, `tests/Fixtures/sales/two-years.csv` (generated once by a
seeded script checked in beside it; weekly pattern, a trend, one spike, one gap).

- As an owner with MFA: create a venue -> inspect -> upload with the proposed mapping -> commit -> overview, metrics
  (all grains) and insights show the expected values (spike found as `anomaly_day`, trend found as `trend_28d_yoy`).
- Then upload a corrected file changing two days -> preview lists them -> commit -> revisions written, metrics and
  insights updated, the old overview numbers for untouched periods unchanged.
- A viewer in the same org reads the overview but cannot inspect, upload or list uploads.
- [ ] **Commit** `test: sales upload journey`

### Task 19: Docs

- [ ] `api/README.md`: uploads (limits, template, scanner drivers, `snapshots` disk, the two scheduled commands).
- [ ] `07-decisions-and-open-questions.md`: Plan C decisions (C1-C4 in §7.1) and open items (design §10) in a §7.5.
- [ ] This plan: an Implementation notes section for any deviations.
- [ ] **Commit** `docs: Plan C setup and notes`

---

## Self-review

- **Spec coverage.** C1 -> Tasks 9, 11; C2 -> Tasks 8, 10; C3 -> Tasks 2, 4; C4 -> Tasks 11-13; C5 -> Task 11 route;
  C6 -> Task 12; C7 -> Task 8; C8 -> Task 2. Design §3 -> Task 2; §3.7 -> Task 4; §3.8 -> Task 5; §3.9 and §6 -> Task 6;
  §4.1 -> Tasks 3, 8, 10; §4.2 -> Tasks 9, 10; §4.3 -> Task 11; §4.4 -> Task 12; §4.5 -> Task 13; §5 -> Tasks 1,
  10-17; §7 -> Tasks 2, 3, 10-13; §8 -> each task's tests and Task 18.
- **Riskiest parts:** the metrics SQL (Task 4, pinned by known-answer fixtures) and commit atomicity with a file
  write (Task 12, tested with a forced failure).
- **Not in this plan:** POS connectors, XLSX, transaction-level files, React screens, benchmarks, AI, `venue.updated`
  webhooks.

## Open Items (raised by this plan)

1. Plan B must merge first (this branch builds on its tenant middleware and problem responses).
2. The design's open items (§10): clamd in hosting, the x1.1 GST approximation, and production object storage for
   snapshots. (Peer-pool integrity is decided; Phase 3 applies it.)
3. **Repo visibility:** `For-Claude-Cloud` is public. Make it private once cloud sessions no longer need it.
