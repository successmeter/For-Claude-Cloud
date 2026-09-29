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
| 6 | Peer-pool integrity: count only POS-verified venues toward thresholds, or cap the manual-upload share of a cohort? | Phase 1 |
| 7 | ~~Web tool details: DB engine, subscriber count, competitor-set data model~~ Answered in `plans/2026-09-29-plan-b-hub-contract-design.md` §1. Still open: how AI is used today. | Phase 0 |
| 8 | Per-org monthly AI budget amounts and model-tier mapping. | Phase 4 |
| 9 | ~~How competitor-set members are identified in the Web tool~~ Answered: by GA4 property ID, name, free-text location and cuisine (no domain, place ID or ABN). Venue matching must rely on data the Hub collects on members (name, website, location). | Phase 3 |
| 10 | Whether to pull AI strategy forward (own-history findings only) for early pilots. | Phase 1 planning |
