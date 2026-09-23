# Revenue Performance Benchmarking Tool — Project Brief

## Vision
A revenue performance benchmarking tool for subscribers (businesses) that ingests
sales/revenue data, benchmarks it against competitor sets, and uses AI to generate
strategic plans from the data.

## Product Ecosystem (3 tools)

### 1. Revenue Performance Benchmarking Tool (THIS PROJECT — new)
- Ingests revenue/sales data from subscribers
- Benchmarks performance against competitor sets
- AI-generated strategic planning based on the data

### 2. Web Performance Benchmarking Tool (EXISTING — separate Git repo)
- Already built, lives in its own repo
- **Repo/app name: "traffic app"** — local checkout at `C:\Users\saura\my-traffic-app.worktrees`
- Must share with this tool:
  - Subscriber account details (same login/identity across both tools)
  - Competitor sets (same competitor groups per subscriber)
- Also connects to AI for strategic planning based on web performance data

### 3. Strategy Execution Tool (FUTURE — not yet designed)
- Consumes the strategic plans produced by tools 1 & 2
- Generates websites and social media posts based on those strategies

## Data Ingestion (Revenue Tool)
Three ingestion paths, subscriber's choice:
1. **Manual entry** — user uploads/enters data directly
2. **Direct POS API integration** — per-POS-system connectors
3. **Third-party POS intermediary** — connect via an aggregator service that
   already integrates with many POS systems

## AI Integration
- Analyze benchmarked data per subscriber
- Generate strategic plans (fed later into the execution tool)
- Both benchmarking tools use AI for strategy generation

## Cross-Tool Requirements
- Single subscriber identity / shared accounts across tools (SSO-style)
- Shared competitor set definitions
- Shared strategy output format consumed by the execution tool

## Non-Functional Requirements
- **Full encryption of subscriber data** (at rest and in transit)
- Security (authN/authZ, tenant isolation, audit)
- Third-party integrations (POS intermediaries, AI provider)
- POS system APIs (direct connectors)

## Deliverables Wanted From This Design Phase
1. High-level technical requirements / architecture design
2. Implementation plan covering:
   - Database design (multi-tenant, per-tool data, shared entities)
   - Backend for integrations (POS, intermediary, AI, cross-tool sharing)
   - UI (subscriber dashboards, benchmarking views, strategy views)
   - Security & encryption approach
   - Phased delivery plan (what ships first, dependencies between tools)
