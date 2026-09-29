# 6. Phased Implementation Plan

Assumes a team of 1 to 3 developers. Durations are **rough ranges**, to be firmed up after Phase 0.

## 6.1 Phases

| Phase | Delivers | Depends on | Rough size |
|---|---|---|---|
| **0. Discovery and decisions** | Per-vendor POS spikes (Square, Impos, OrderMate, Bepoz, Idealpos, H&L). Intermediary vendor chosen. AI provider and AU-residency decision. Hosting confirmed. Privacy and consent terms reviewed. ABS geo data loaded. Web tool audit (auth model, competitor-set model, user counts, DB engine). | none | 2-3 weeks |
| **1. Hub and core product** | Laravel modular monolith; Postgres with RLS; KMS envelope encryption; auth with MFA; orgs, venues, users; audit log; OIDC provider; **hub contract v1**. React shell, onboarding, CSV upload into `sales_daily`. Daily metrics, Overview screen, own-history insights (trends, anomalies). | Phase 0 | 6-8 weeks |
| **2. Automated ingestion** | Connector framework; Square connector; nightly sync and backfill; Data sources UI; intermediary adapter; direct connectors for other POS systems as spikes justify. | Phase 1 | 6-10 weeks (driven by POS findings) |
| **3. Benchmarking** | Consent capture; peer-pool pipeline with k >= 5 constraints and nested suppression; market / segment / cuisine cohorts; Benchmarks UI with unavailable states; competitor sets with venue matching and the set view. | Phases 1-2 (pool needs data) | 6-8 weeks |
| **4. AI strategy** | Insight-engine findings; playbook rules; AI gateway; materiality gate, cache and budgets; plan schema v1; Strategy UI. | Phase 1 (own-history), Phase 3 (cohort findings) | 4-6 weeks |
| **5. Launch readiness** | Pen test, load test, restore drill, legal sign-off, pilot with roughly 10-20 venues. | Phases 1-4 | 2-3 weeks |

**Ordering rationale:** ingestion (Phase 2) precedes benchmarking (Phase 3) because frictionless data drives the opt-ins
that benchmarks depend on. Phase 4 can be pulled forward on own-history findings alone if an early pilot demo is wanted,
since it needs no cohort data.

## 6.2 Web Performance tool track (parallel)

Runs alongside the phases above. Changes to the Web tool are moderate. It turned out to be a pitch/demo build with no authentication, so no backward-compatible migration is needed; W1 and W2 are designed in `plans/2026-09-29-plan-b-hub-contract-design.md`.

| Step | Change | Needs |
|---|---|---|
| **W1** | SSO: Web tool becomes an OIDC client (no existing Web users to link). | Hub contract v1 (after Phase 1) |
| **W2** | Competitor sets: Hub owns sets with per-set `tools[]` visibility; Web tool consumes and edits them via the API with a local cache and webhook pings; GA4 links stay in the Web tool. Contract part in Plan B; venue matching in Phase 3. | Plan B (Phase 3 for matching) |
| **W3** | Shared strategy store: Web tool posts plans in the shared schema. | Phase 4 |

## 6.3 Strategy Execution tool (future)

Gets its own brainstorming, spec and plan cycle. It needs from this project: a stable plan schema v1, the hub contract, and real
plans in production. Nothing else is built for it now.

## 6.4 After launch

- Additional metrics: average spend, covers, dayparts, category and channel mix. Then margin and labour, which need extra data sources.
- NZ expansion through the geo hierarchy.
- Optional: extract the Hub into a standalone Platform service if the ecosystem grows.

## 6.5 Milestones and gates

- End of Phase 1: internal demo; venues can upload data and see their own performance.
- End of Phase 2: private pilot onboarding via POS connections.
- End of Phase 3: benchmarks live for cohorts that meet the threshold.
- End of Phase 5: public launch, gated by legal sign-off and the security exercises.

## 6.6 Top risks

| Risk | Mitigation |
|---|---|
| POS access unknown for the AU systems | Phase 0 spikes; launch with Square, manual upload and the intermediary |
| Cold-start participation (benchmarks empty) | Strong empty states, own-history value, pilot recruitment, ingestion before benchmarks |
| Consent and legal terms | Legal review in Phase 0; consent covers pool and competitor-set aggregates |
| AI residency and provider | Decision in Phase 0; provider-agnostic gateway; de-identified findings only |
| Peer-pool poisoning | Verification rule decided in Phase 1 (see 05) |
| Web tool migration | Demo only, so no live migration; staged W1 to W3 |
