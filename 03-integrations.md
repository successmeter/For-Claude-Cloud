# 3. Backend Integrations

## 3.1 POS ingestion: one pipeline, three paths

```
Manual upload ----+
Direct connector -+-> Normaliser (validate, GST basis, business-day cutoff) -> sales_daily (idempotent upsert)
Intermediary -----+        ^ PII-stripped snapshot -> encrypted object storage (90 days)
```

**Connector interface.** Every connector, direct or intermediary, implements:

- `authorize()`, `listLocations()`, `fetchSales(range, cursor)`, `health()`
- capability flags: webhooks supported, history depth, rate limits

The pipeline is indifferent to the source.

**Sync behaviour**

- Nightly pull, plus webhooks where the POS provides them.
- Backfill on connect: up to 24 months, or as much as the source offers (YoY needs at least 12).
- Re-pull the last 7 days nightly to catch POS revisions.
- Retries with exponential backoff, a circuit breaker per connection, and connection health shown in the UI.
- Per-venue **business-day cutoff** (a trading day often ends after midnight) and **GST inclusive/exclusive**
  flag, normalised at ingest.
- Credentials: KMS envelope-encrypted; decrypted only inside connector jobs; never logged.

**Systems**

| System | Plan |
|---|---|
| Square | First direct connector, OAuth. |
| Impos, OrderMate, Bepoz, Idealpos, H&L | API openness is **unknown**; several are venue-hosted. Phase 0 runs a discovery spike per vendor (API, partner programme, export, or on-site agent). Until resolved they use manual upload or the intermediary. |
| Lightspeed / other | Not confirmed as required; revisit after Phase 0. |

**Manual upload:** CSV/XLSX template, column-mapping step, validation, preview before commit. Also the
universal fallback. Uploads are size/type-limited, malware-scanned, parsed defensively, and CSV
formula-injection escaped.

**Intermediary:** one adapter implementing the connector interface. Vendor is chosen in Phase 0 against:
coverage of the five AU POS systems, AU data residency, history depth, freshness, webhooks, per-venue pricing.

## 3.2 AI gateway (provider-agnostic)

- Interface: `generate(task, findings, tier) -> plan JSON`, with per-provider adapters. Provider is not yet chosen.
- The input schema is a **whitelist of findings fields**, so raw or personal data cannot reach a provider.
- Pipeline: materiality gate -> cache lookup by findings hash -> budget check -> call -> schema
  validation -> **traceability check** (every figure must map to a finding) -> ledger write.
- Prompts are versioned; usage and cost are logged per call.
- AU-residency: prompts contain only de-identified findings, but whether an offshore provider is acceptable
  must be decided before launch (see 07).

## 3.3 Cross-tool contract (Hub is in the Revenue tool)

| Concern | Mechanism |
|---|---|
| Login | Hub is an OIDC provider (Laravel Passport). Web tool is a relying party. Tokens are short-lived (15 min) with rotating refresh tokens. |
| Account linking | On first SSO, match a Web user by verified email into `external_identities`; migrate gradually. Existing Web logins keep working during transition. |
| Service APIs | OAuth client-credentials with per-tool scopes for orgs, venues, competitor sets (filtered by `tools[]`) and the strategy store. |
| Events | Signed webhooks (HMAC and timestamp): `org.updated`, `venue.updated`, `competitorset.changed`. Consumers keep a small local cache and never query Hub tables. |
| Versioning | The contract is versioned (v1 defined in Phase 1) and changes are backward-compatible within a version. |

### Shared strategy plan schema (v1 sketch)

```json
{
  "schema_version": "1.0",
  "id": "uuid",
  "org_id": "uuid",
  "venue_id": "uuid",
  "source_tool": "revenue | web",
  "generated_at": "iso8601",
  "period": { "from": "date", "to": "date" },
  "summary": "string",
  "goals": [{ "id": "g1", "text": "string", "metric": "string", "target": "string" }],
  "actions": [{
    "id": "a1",
    "lever": "string",
    "channel": "website | social | promo | ops",
    "priority": 1,
    "expected_impact": "low | medium | high",
    "effort": "low | medium | high",
    "timeline": "string",
    "rationale_finding_ids": ["f3", "f7"],
    "content_brief": "string"
  }],
  "findings_ref": ["f3", "f7"]
}
```

The Execution tool will consume `actions` (especially `channel` and `content_brief`) to generate websites
and social posts. It is not designed yet; this schema is the agreed interface point, to be finalised
in Phase 4.
