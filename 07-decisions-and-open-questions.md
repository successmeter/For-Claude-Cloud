# 7. Decisions, Assumptions and Open Questions

## 7.1 Decisions made in the interview

| Topic | Decision |
|---|---|
| Existing Web tool | **Corrected 2026-09-29 (Plan B design):** Node/Express API with React/Vite frontends and Postgres (Neon); no authentication yet (header-based tenant); pitch/demo only, no real subscribers. Originally recorded as Laravel with its own auth. |
| New backend / UI | Laravel API; React SPA on Mosaic React. |
| Vertical and market | Restaurants, cafes, bars. Australia first, NZ and others later. |
| Benchmark data source | Anonymised peer pool of opted-in subscribers. |
| First-release POS | Square, Impos, OrderMate, Bepoz, Idealpos, H&L (others via intermediary or manual). API access not yet investigated. |
| AI provider | Provider-agnostic; decide later. Keep AI cost low: statistics first, AI on material change only. |
| Tenancy | Organisation with multiple venues and multiple users. |
| MVP metrics | Revenue and growth (daily data; weekly and monthly are rollups). |
| Cross-tool hub | The Revenue tool is the hub, as a Hub module in the Laravel app. Competitor sets are shared by default; each tool may keep its own. |
| Competitor-set benchmark | Average of set members who are opted-in subscribers; needs 5 distinct orgs. |
| Benchmark thresholds | At least 5 participants for market, cuisine/segment and competitor-set views; otherwise not shown. |
| Peer-pool integrity | **Decided 2026-09-29.** Only **verified** venues count toward thresholds (k >= 5 distinct orgs excluding the viewer, >= 2 orgs, no-dominance, nested suppression). Unverified (uploaded) venues join an average only once a cohort qualifies on verified venues alone, capped at half the contributors. Verified = POS/intermediary-sourced data, or an upload venue that passes a check (active ABN, venue matched in Google Places, one staff-approved POS report sample); staff-onboarded pilot venues count as verified. Reason: attacker-controlled fake uploads could otherwise make up 4 of 5 contributors and reveal the fifth. Applied in Phase 3; Plan C records each sales row's source. |
| Revenue app (Plan D) | **Decided 2026-09-30.** Real data where it exists, the mock-up's market cards kept as a labelled preview on sample data; **invitation-only** accounts for the pilot (staff invite a business with `hub:invite-business`, owners invite their team); unused Mosaic template pages removed; competitor sets edited in the app too. The app stays on Netlify and reaches the API through a same-origin proxy (`/api`, `/sanctum`). |
| Sales uploads (Plan C) | **Decided 2026-09-29.** CSV only, daily totals only (one row per business day; no transaction-level files). An upload is previewed (new, changed, unchanged days) and then committed; committing overwrites changed days and keeps their history; any problem row blocks the commit. |
| Revenue basis | **Decided 2026-09-29.** Metrics and insights use GST-inclusive revenue. Each day keeps the figure as supplied with its GST flag; ex-GST figures are converted with x1.1. |
| Geography | Progressive filters; region default (e.g. Greater Perth); market (suburb) narrows. No silent widening. |
| Encryption | Storage-level for all data; app-level per-org envelope encryption for credentials, files, snapshots and personal fields. Sales values are not per-value encrypted. |

## 7.2 Assumptions (not confirmed by you; change if wrong)

- **Hosting is AWS Sydney (ap-southeast-2).** Hosting has not been decided.
- Markets are ABS suburbs/localities; regions are ABS Greater Capital City areas.
- "Segment" means business format (restaurant, cafe, bar) and "cuisine" means restaurant type.
- Cohorts also require at least 2 distinct orgs and a no-dominant-venue check (a proposed addition to the 5-participant rule).
- Competitor-set edit lock of 30 days on matched composition; source snapshot retention of 90 days; 24-month backfill.
- Backup targets RPO 15 minutes and RTO 4 hours.
- Team of 1 to 3 developers; tens to low hundreds of venues at launch.
- "Lightspeed / other" was ticked in the POS question, but no specific Lightspeed requirement was stated.

## 7.3 Open questions

| # | Question | Needed by |
|---|---|---|
| 1 | Do Impos, OrderMate, Bepoz, Idealpos and H&L have accessible APIs, partner programmes, exports or need an on-site agent? | Phase 0 |
| 2 | Which POS intermediary (coverage of the five AU systems, AU residency, history depth, pricing)? | Phase 0 |
| 3 | Which AI provider, and is offshore processing of de-identified findings acceptable under your privacy terms? | Phase 0 |
| 4 | Hosting provider and region confirmation. | Phase 0 |
| 5 | Legal review: Privacy Act obligations, contribution consent wording (must cover competitor-set aggregates), data breach process. | Phase 0 to 5 |
| 6 | ~~Peer-pool integrity: count only POS-verified venues toward thresholds, or cap the manual-upload share of a cohort?~~ **Decided 2026-09-29**, see §7.1 "Peer-pool integrity". | Phase 1 |
| 7 | ~~Web tool details: DB engine, subscriber count, competitor-set data model~~ Answered in `plans/2026-09-29-plan-b-hub-contract-design.md` §1. Still open: how AI is used today. | Phase 0 |
| 8 | Per-org monthly AI budget amounts and model-tier mapping. | Phase 4 |
| 9 | ~~How competitor-set members are identified in the Web tool~~ Answered: by GA4 property ID, name, free-text location and cuisine (no domain, place ID or ABN). Venue matching must rely on data the Hub collects on members (name, website, location). | Phase 3 |
| 10 | Whether to pull AI strategy forward (own-history findings only) for early pilots. | Phase 1 planning |

## 7.4 Open items from Plan B (Hub contract and Web competitor sets)

Details in `plans/2026-09-29-plan-b-hub-contract-design.md` §8 and the implementation plan's open items.

- **Web hosting domains** must be same-site with the Web API for its session cookie.
- **Staff/admin authentication** for the Web admin frontend and Hub support access is undesigned; the Web admin API
  is off outside development until it is.
- **GA4 access consent:** whether a competitor granting the service account access is enough consent for use in other
  subscribers' averages belongs in the legal review (open question 5).
- **Passport signing keys** in production come from the secrets manager; rotation tooling is not built.
- **OIDC package risk:** `jeremy379/laravel-openid-connect` has one maintainer; pinned to `^3.3` with
  `AuthorizationCodeFlowTest` as the upgrade gate.
- **Repository visibility:** `For-Claude-Cloud` is public; make it private once cloud sessions no longer need it.

## 7.5 Open items from Plan C (sales uploads, metrics, insights)

Details in `plans/2026-09-29-plan-c-sales-data-design.md` §10.

- **ClamAV in production:** uploads are refused unless clamd answers, so the hosting plan needs a clamd service
  (open question 4).
- **Snapshot storage:** production needs AU-region object storage for the `snapshots` disk.
- **GST conversion:** x1.1 is approximate for venues with GST-free sales; such venues should upload GST-inclusive
  figures until a per-venue GST-free share is added.
- **Venue verification** (for peer-pool integrity, §7.1) is designed in Phase 3; Plan C only records each row's source.

## 7.6 Open items from Plan D (the Revenue app)

Details in `plans/2026-09-30-plan-d-revenue-app-implementation-plan.md` (Implementation notes).

- **Publishing the app:** `main` of `performance-benchmarking` publishes live. Merge Plan D there only once the Hub is
  hosted and `VITE_API_ORIGIN` is set in Netlify (the build fails without it, so a premature merge keeps the old site).
- **App address:** `infra/cdk.json` assumes `https://app.successmeter.tech` (invitation links and the sign-in cookie's
  allowed host). Point that domain at the Netlify site, or change `frontendUrl` to the Netlify address.
- **Password reset** is built (2026-10-01): the link opens the Revenue app; a reset signs the person out everywhere,
  including the Web tool. It needs production mail (SES) like invitations.
- **SES production access** is needed before invitations reach people outside verified addresses.
- **Rate limits by IP** behind the Netlify proxy see Netlify's address (API README); generous enough for the pilot.
- **Business profile** (ABN, trading name) is a preview kept in the browser; it needs an API before it is real.
- **Web tool hosting** is ready in the same AWS setup (`docs/hosting-aws.md`, "The Web Performance tool"); its Netlify
  site needs `WEB_API_ORIGIN` and the `web.successmeter.tech` address before its `main` is merged.
