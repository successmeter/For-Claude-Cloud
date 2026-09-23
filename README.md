# Revenue Performance Benchmarking Tool: Design

Status: **draft for review**, produced from a brainstorming interview against `REQUIREMENTS.md`.
Scope: high-level technical design and phased plan. No code has been written.

## Reading order

| # | Document | Covers |
|---|---|---|
| 1 | [01-architecture.md](01-architecture.md) | System shape, modules, data flow, statistics-first AI, cross-tool topology |
| 2 | [02-data-model.md](02-data-model.md) | Postgres schemas, multi-tenancy, peer pool, geography, benchmark rules |
| 3 | [03-integrations.md](03-integrations.md) | POS ingestion, intermediary, AI gateway, cross-tool contract and strategy schema |
| 4 | [04-ui.md](04-ui.md) | React SPA screens, navigation, unavailable-state behaviour |
| 5 | [05-security.md](05-security.md) | Encryption, authN/authZ, tenant isolation, privacy, audit, hardening |
| 6 | [06-implementation-plan.md](06-implementation-plan.md) | Phases, dependencies across all three tools, risks |
| 7 | [07-decisions-and-open-questions.md](07-decisions-and-open-questions.md) | Decisions made in the interview, assumptions, unresolved items |

## One-paragraph summary

A Laravel modular monolith (Postgres, queue workers, KMS-backed encryption) with a React SPA
(Mosaic React template). It ingests daily restaurant/cafe/bar revenue via manual upload, direct POS
connectors or a POS intermediary, and benchmarks each venue against an anonymised peer pool by
market, segment and cuisine, and against its chosen competitor set. Every benchmark requires at
least five participants or it is not shown at all. A deterministic statistics layer produces
findings first; AI is called only on material change, with a small structured input, to turn findings
into a strategy plan. The Revenue tool is the identity and shared-entity hub (via an internal Hub
module): the existing Web Performance tool joins it through OIDC and a versioned contract, and the
future Strategy Execution tool reads plans from one shared store.

## Ecosystem at a glance

```
                    +-------------------------------+
                    |  Revenue tool (Laravel + SPA) |
                    |  Hub module: identity (OIDC), |
                    |  orgs, venues, competitor sets|
                    |  Strategy store (shared plans)|
                    +---------+-------------+-------+
        OIDC + contract |                   | plan schema v1
                        v                   v
              Web Performance tool     Strategy Execution tool
              (existing, Laravel)      (future, not yet designed)
```
