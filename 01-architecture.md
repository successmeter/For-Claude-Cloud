# 1. Architecture

## 1.1 Context and constraints

- Subscribers are **restaurants, cafes and bars**, launching in **Australia** (NZ later).
- Backend is **Laravel**, matching the existing Web Performance tool. Frontend is a **React SPA**
  on the Mosaic React template, talking to a Laravel JSON API.
- The **Revenue tool is the hub** for identity, orgs, venues and competitor sets. The Web tool
  (existing, live, own auth today) moves onto the hub over time. Moderate changes to the Web tool are acceptable.
- Hosting is not decided. This design assumes **AWS Sydney (ap-southeast-2)**; it uses only managed
  primitives (managed SQL, KMS, object store, queue, secrets manager) so it ports to another cloud.
- Team assumption: 1 to 3 developers, tens to low hundreds of venues at launch.

## 1.2 Shape: modular monolith with hard boundaries

```
React SPA (Mosaic React)
        | HTTPS / JSON (Sanctum httpOnly cookie session)
Revenue Laravel app (one deployable)
 +- Hub module        identity (OIDC provider), orgs, venues, users/roles, competitor sets, geo
 +- Ingestion module  manual upload | direct POS connectors | intermediary adapter -> canonical sales
 +- Benchmark module  daily metrics, cohorts, peer-pool aggregates, k>=5 enforcement
 +- Strategy module   insight engine, playbook rules, AI gateway, strategy plans
 +- Security/Audit    encryption, tenant context, audit log
        |                    |                      |
 Queue workers        Postgres + encrypted     Web tool (later), Execution tool (future)
 (sync, recompute,    object storage + KMS     via the Hub's public contract
  AI jobs)
```

Rules that keep it extractable:

- Each module owns its schema and exposes only an interface or event. **No cross-module joins.**
- The Hub is reached by other tools only through its public contract (OIDC, REST, signed webhooks).
  No tool reads another tool's tables. If the Hub is later split into a Platform service, only the
  contract's target changes.
- Tenant data and the peer pool are separate schemas with separate DB roles (see 02 and 05).

## 1.3 Data flow

1. **Ingest.** Manual upload, connector pull or intermediary pull produces source records.
   A PII-stripped, whitelisted source snapshot is archived (encrypted, 90 days).
2. **Normalise.** Validate, apply the venue's business-day cutoff and GST basis, then idempotently
   upsert into `sales_daily`.
3. **Metrics.** Daily venue metrics are rebuilt incrementally for only the affected days.
   Weekly and monthly views are rollups of the daily table.
4. **Contribute.** A one-way job turns opted-in venues' daily metrics into de-identified cohort
   aggregates in `peer_pool`, only where participation thresholds are met.
5. **Benchmark.** Queries compare a venue's metrics with the cohort or competitor-set aggregate.
   Below threshold, the API returns an *unavailable* state, never partial data.
6. **Insight and strategy.** The statistics layer produces findings; the AI gateway is called only
   when warranted (1.4). Plans are stored in the shared strategy store.

## 1.4 Statistics first, AI last (cost control)

```
Canonical sales + available cohort aggregates
        v
Insight engine (deterministic, no per-call cost)
  trends (WoW/MoM/YoY, same-weekday, seasonality-adjusted), anomalies (robust z-score/MAD),
  cohort position (percentile and gap), findings ranked by size of gap and confidence
        v
Playbook rules: finding type -> candidate levers (deterministic library)
        v
Materiality gate: call AI only if findings changed meaningfully
        v
AI gateway (small structured prompt): prioritise, tailor, narrate -> strategy plan JSON
```

- The AI never sees raw data. Its input is a whitelisted **findings object** (a few hundred tokens).
  Every number in a plan must trace to a finding, and untraceable figures cause the output to be rejected.
- Cost controls: materiality gate; cache by findings hash; two model tiers (cheap for routine
  weekly plans, stronger for on-demand deep plans); per-org monthly token budget with hard cap;
  batch/scheduled execution for non-urgent plans; length-limited structured output; per-call cost ledger.
- Statistics run in Postgres SQL (window functions, `percentile_cont`) and PHP. A separate Python
  service is deferred until a model genuinely needs it.
- **Cold start:** with too few participants, cohort findings are unavailable, and the engine uses
  the venue's own history (trend, seasonality, anomalies) and marks any cohort statements as absent.

## 1.5 Cross-tool topology

- **Approach chosen:** the Revenue tool is the hub, with the Hub module inside its Laravel app.
- **Identity:** Hub is an OIDC provider (Laravel Passport). The Web tool becomes a relying party.
  Existing Web users are linked by verified email on first SSO, then migrated gradually.
- **Competitor sets** belong to the subscriber (org), with a `tools[]` visibility list. A set can be
  shared (`{revenue,web}`) or private to one tool. Adding the Execution tool later means adding a value.
- **Strategy store:** the Revenue tool and the Web tool both write plans in one versioned schema; the
  Execution tool reads from that single place.
- Details of the contract are in [03-integrations.md](03-integrations.md).
