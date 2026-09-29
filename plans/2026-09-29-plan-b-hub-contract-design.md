# Plan B Design: Hub Contract v1 (OIDC) and Shared Competitor Sets

Status: **design, approved choices recorded; implementation plan not yet written.**
Date: 2026-09-29. Produced by a brainstorming pass over `06-implementation-plan.md`, `07-decisions-and-open-questions.md`,
the Foundation plan (Plan A) and a read of the Web Performance tool's code (`successmeter/traffic-dashboard` @ `2272d51`).

Plan B delivers roadmap items **W1** (SSO) and the identity/contract half of **W2** (competitor sets) from 06 §6.2, plus the
Hub pieces of Phase 1 they depend on (OIDC provider, hub contract v1). It does **not** deliver venue matching,
competitor-set revenue benchmarks, or the strategy store (W3); those stay with the benchmarking and AI plans.

---

## 1. What the Web tool actually is (findings that change 07-decisions)

| 07 says | Code says | Consequence |
|---|---|---|
| Web tool is Laravel with its own auth | **Node/Express 5** API (`server/index.js`), two React 19/Vite frontends (`src/` customer, `admin/` staff), deployed as Netlify sites | Web-side work is JavaScript; the OIDC client is `openid-client`, not a Laravel package |
| Web tool has its own auth and users to link by verified email | **No authentication.** Tenant comes from an unauthenticated `x-tenant-id` header or `DEV_TENANT_ID` (`server/index.js:21`). README: "pitch build ... add verified authentication before production" | Nothing to link or migrate. W1 is "give the Web tool auth, via the Hub" |
| DB engine unknown (open question 7) | **Postgres (Neon)**, schema in `server/schema.sql` | Irrelevant to the contract (tools share via API), but the Web side can use Postgres for sessions/cache |
| Competitor members identified by domain/address/place/ABN (open question 9) | Competitors are rows in `properties` with `role='competitor'`, keyed by **`ga4_property_id`**, plus free-text `location` and `cuisine_type`. Sets are a text label (`competitor_set`) or dynamic (`chosen` flag, `market`, `cuisine`) | A shared set member must be tool-neutral (a business), with each tool holding its own data handle |
| k >= 5 for competitor-set views | Web shows an average with **>= 3** chosen competitors | Raised to 5 (decision B6) |

User-confirmed facts: the Web tool is **pitch/demo only** (no real subscriber data), and a Web `tenant` is **one subscriber
business** (= one Hub org). The Web `subscribers` table is internal CRM/billing metadata about that tenant.

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| B1 | **Hub is an OIDC provider built on Laravel Passport plus an OIDC layer** (id_token, discovery, JWKS, PKCE). Candidate package: `jeremy379/laravel-openid-connect`; verify Laravel 13 / Passport compatibility in Task 1, fall back to Passport's custom response-type hook if it is not compatible. | Reuses Foundation users, Argon2id, TOTP MFA and audit; standard OIDC so the Execution tool joins the same way later. External IdP rejected (moves users/MFA out of built code, extra service); ad-hoc JWT hand-off rejected (non-standard, replaced later anyway). |
| B2 | **No Web account migration.** The Web tool never had users. `external_identities` (02 §2.1) is not built in Plan B. | Nothing to link. Add it only if a tool with its own existing users joins later. |
| B3 | **Web tenant maps 1:1 to a Hub org** via `org_tool_links`. Existing demo tenants are discarded, not migrated. | Pitch-only data. |
| B4 | **Hub is the single source of truth for competitor sets.** Web reads through the Hub API (with a local cache) and writes through the Hub API using the **signed-in user's access token**, so Hub roles decide who may edit. | One authorisation model; no dual-write. |
| B5 | **Members are businesses; data handles are per-tool and stay in each tool.** The Hub stores name, website, location, cuisine. The Web tool stores `hub_member_id -> ga4_property_id` in its own DB. Revenue's venue matching (later plan) adds its own handle. | Hub stays tool-agnostic; no tool reads another's data. A member added from the Revenue tool shows in Web as "not connected to GA4" and is excluded from Web averages until linked. |
| B6 | **Web competitor averages require >= 5 GA4-linked members** (was 3). Same rule as 07 §7.1. | One rule across tools. Acceptable while Web is demo-only. |
| B7 | **Events are thin signals.** Webhooks carry `{event, org_id, entity_id, occurred_at}` only; the consumer refetches via the API. HMAC-signed, delivered from a transactional outbox. | No set data in transit through webhooks; replay/ordering reduces to "refetch latest". |
| B8 | **Tenant context becomes transaction-scoped** (`set_config(..., true)` inside a transaction) for requests and jobs. Resolves Plan A Open Item 2. | Plan B introduces the first queued jobs; session-scoped settings leak across pooled/persistent connections. |
| B9 | **Hub code lives under `App\Hub\` in the existing app, tables stay in the `public` schema.** Moving to per-module Postgres schemas (02 intro) is deferred; it would mean moving Plan A's tables too. | Keep Plan B focused. Namespace boundary now, schema boundary later. |

## 3. Architecture

```
Browser (Web SPA, customer)            Browser (Revenue SPA, later)
   | cookie session (httpOnly)              | Sanctum cookie session (Plan A)
   v                                        v
Web backend (Express)  --- OIDC auth code + PKCE --->  Hub (Laravel api/)
  - session store (Postgres)                             - /oauth/* + /.well-known/*   (Passport + OIDC)
  - hub client (user token | client creds) --REST v1-->  - /hub/v1/*                   (contract v1)
  - competitor-set cache, ga4 links                      - hosted login + MFA page (Blade)
  - POST /hooks/hub  <--- signed webhooks (outbox) ----  - webhook outbox + delivery job
```

**Web backend as a confidential client (BFF).** Tokens never reach the browser. The Express app runs the authorization
code flow with PKCE, stores `{sub, access_token, refresh_token, selected_org_id}` in a server-side session
(`express-session` + Postgres store), and gives the browser an httpOnly, Secure, `SameSite=Lax` cookie. The frontends call
the Web API with `credentials: 'include'`. **Deployment constraint:** the Web API and the customer frontend must share a
registrable domain (e.g. `web.example.com` + `api.web.example.com`) so the cookie is first-party; Netlify default
`*.netlify.app` domains will not work for production.

## 4. Hub side (Laravel `api/`)

### 4.1 Identity provider

- **Endpoints:** `/.well-known/openid-configuration`, `/oauth/authorize`, `/oauth/token`, `/oauth/jwks`,
  `/oauth/userinfo`, `/oauth/revoke`, `/oauth/logout` (RP-initiated logout with `post_logout_redirect_uri` allow-list).
- **Hosted login page.** `/oauth/authorize` needs an interactive login on the Hub origin, which Plan A does not have (its
  login is a JSON API). Plan B adds minimal server-rendered pages (Blade): email/password, then TOTP when enrolled,
  reusing `LoginController`/`MfaController` logic (extract the shared parts into an `Authenticator` service, keep the
  existing JSON routes and their rate limiters unchanged). First-party clients skip the consent screen.
- **Clients:** registered per tool (`web` confidential client now; `revenue-spa` not needed, it uses Sanctum).
  Exact-match redirect URIs, PKCE required for all clients, `client_credentials` grant enabled for the `web` client.
- **Tokens:** access token JWT, 15 min; refresh tokens rotate on use and the previous one is revoked. **Reuse detection**
  (a revoked refresh token presented again revokes the whole grant) is not Passport default and is added in Plan B.
  id_token signed RS256, key in the secrets manager (local file key in dev, like `LocalFileKmsDriver`).
- **Claims:** `sub` = new `users.public_id` (UUID, never the bigint PK), `email`, `email_verified`, `name`.
  (`amr`/`auth_time` were dropped after the spike: the token endpoint is a back-channel call with no browser session,
  and the Hub enforces MFA at login itself. Access-token JWTs carry the internal id as `sub`, so clients must treat
  access tokens as opaque.) Org memberships are **not** in tokens; they come from
  `GET /hub/v1/me/orgs` so tokens stay small and role changes apply within one access-token lifetime.
- **Scopes:** `openid email profile orgs competitor-sets:read competitor-sets:write offline_access`.
- **Audit:** logins via OIDC, token grants, refresh reuse, logout and client-credentials token issuance are audit-logged.

### 4.2 Tenant resolution for API calls

- **User tokens:** the caller sends `X-Hub-Org: <org uuid>`. Middleware verifies an active membership of the token's
  user in that org, then sets tenant context. No membership -> 404 (not 403, to avoid confirming org existence).
- **Client-credentials tokens:** same header; middleware verifies an `org_tool_links` row for (org, client's tool).
  A tool can only reach orgs that linked it.
- **SPA (Sanctum) requests:** the same `X-Hub-Org` header and membership check (changed from "session's selected
  org" during planning: one mechanism for every caller, same security).
- `SetTenantContext`'s temporary `X-Org-Id` header path (local/testing only) is **removed**; tests use the real path.

### 4.3 Transaction-scoped tenant context (Plan A Open Item 2)

- `TenantContext::run(string $orgId, Closure $fn)` opens a DB transaction, calls `set_config('app.current_org_id', ?, true)`,
  runs `$fn`, commits. `set`/`clear` are removed.
- HTTP: the tenant middleware wraps the downstream handler in `run()`. Requests without an org run with no context
  (RLS returns nothing), which is the safe default.
- Jobs: a `RunsWithTenantContext` job middleware reads the job's required `orgId` property and wraps `handle()` in `run()`.
  A job that touches tenant tables without it sees zero rows (tests assert this).
- Outbound HTTP (webhook delivery) never runs inside a tenant transaction; see 4.6.
- Fixes the `StatefulOriginGatingTest` leak noted in Plan A.

### 4.4 Data model (new tables)

| Table | Columns (key ones) | RLS |
|---|---|---|
| `geo_areas` | `id uuid`, `parent_id`, `level` (`market`/`region`/`state`/`country`), `code`, `name` | none (reference data); FK `venues.market_id -> geo_areas.id` added (promised by Plan A). ABS data load is **not** in Plan B; tests use a small fixture. |
| `org_tool_links` | `org_id`, `tool` (`web`), `external_tenant_ref` (Web tenant id, opaque to Hub), `linked_by`, `linked_at`, `revoked_at` | yes |
| `competitor_sets` | `id uuid`, `org_id`, `name`, `tools text[]` (CHECK subset of `{revenue,web}`, non-empty), `version int`, `composition_locked_until`, `activated_at`, timestamps, soft delete | yes |
| `competitor_set_members` | `id uuid`, `org_id`, `set_id`, `name`, `website_url`, `location_text`, `market_id` (nullable FK), `cuisine`, `removed_at` | yes |
| `webhook_endpoints` | `id`, `tool`, `url`, `secret` (envelope-encrypted with a platform key), `active` | none (platform config) |
| `webhook_outbox` | `id uuid` (= event id), `tool`, `event`, `org_id`, `entity_id`, `occurred_at`, `attempts`, `next_attempt_at`, `delivered_at`, `last_error` | none; holds ids only |
| `users.public_id` | new UUID column, unique, backfilled | n/a |

Deferred to the benchmarking plan: `matched_venue_id`, `venue_competitor_sets`, composition snapshots for revenue
aggregates, `contribution_consent`.

**Grants (Plan A Open Item 4).** Add `ALTER DEFAULT PRIVILEGES ... GRANT SELECT, INSERT, UPDATE ON TABLES TO app_user`
(deliberately **no DELETE**, which Plan A showed can bypass RLS via cascading FKs); tables needing DELETE get an explicit
grant. Add a catalog test that fails if any table with an `org_id` column lacks `FORCE ROW LEVEL SECURITY` and a policy,
or if `app_user` holds a privilege not in the expected map. This removes the per-table checklist failure mode.

### 4.5 Contract v1 REST API (`/hub/v1`)

All responses JSON; errors are RFC 9457 `application/problem+json`. Collections support `ETag`/`If-None-Match`.
JSON Schemas for every payload are committed at `api/contract/v1/*.schema.json` and are the contract's source of truth.

| Method and path | Scope / role | Notes |
|---|---|---|
| `GET /me` | `openid` | `sub`, email, name |
| `GET /me/orgs` | `orgs` | `[{id, name, role}]` |
| `GET /orgs/{org}` | `orgs` | id, name |
| `PUT /orgs/{org}/tools/{tool}` | owner, MFA | Links the tool; body `{external_tenant_ref}`. Idempotent. Emits `org.tool_linked` |
| `GET /orgs/{org}/competitor-sets?tool=web` | `competitor-sets:read` | Only sets whose `tools` contains the caller's tool (client-credentials) or the requested tool (user) |
| `GET /orgs/{org}/competitor-sets/{set}` | read | Set with active members and `version` |
| `POST /orgs/{org}/competitor-sets` | write; owner or manager | `{name, tools[]}` |
| `PATCH /orgs/{org}/competitor-sets/{set}` | write; owner or manager | name, tools. `If-Match: <version>` required |
| `DELETE /orgs/{org}/competitor-sets/{set}` | write; owner | soft delete |
| `POST /orgs/{org}/competitor-sets/{set}/members` | write; owner or manager | `{name, website_url?, location_text?, cuisine?}` |
| `PATCH/DELETE /orgs/{org}/competitor-sets/{set}/members/{member}` | write; owner or manager | subject to the composition lock (4.7) |
| `POST /orgs/{org}/competitor-sets/{set}/activation` | read (client-credentials allowed) | Marks the set activated (4.7). Idempotent |

Viewers can read only. Client-credentials tokens get **read only**; writes always need a user token.

### 4.6 Events and webhook delivery

- Events: `org.updated`, `org.tool_linked`, `competitorset.changed` (created, renamed, tools changed, members changed,
  deleted: one event type, consumer refetches). Payload: `{id, event, org_id, entity_id, occurred_at, contract: "v1"}`.
- Outbox row is written **in the same transaction** as the change, only for tools present in the set's `tools[]` (or linked
  to the org, for org events).
- `DeliverWebhooks` job (no tenant context; reads only the outbox) POSTs with headers
  `Hub-Event-Id`, `Hub-Timestamp`, `Hub-Signature: v1=HMAC-SHA256(secret, timestamp + "." + body)`.
  Retries with exponential backoff up to 24 h, then marked failed and surfaced in logs/metrics.
- Consumers must reject timestamps older than 5 minutes, dedupe on `Hub-Event-Id`, and treat delivery as at-least-once.
- Secrets rotate with overlap: two active secrets per endpoint, both signatures sent during rotation.

### 4.7 Differencing protection for Web averages

Raising the threshold to 5 does not stop someone from removing one competitor and comparing the before and after averages
to learn that competitor's GA4 traffic. Because the Hub owns set edits, Plan B applies a Hub-level rule to any set
visible to `web`:

- A set is **activated** the first time a consumer reports it served a benchmark from it
  (`POST /orgs/{org}/competitor-sets/{set}/activation`, called by the Web tool, idempotent).
- Before activation, members can be edited freely.
- After activation, **removing** a member sets `composition_locked_until = now() + 30 days`, and further removals are rejected
  with 409 until then. Additions are always allowed.
- A removal that would leave fewer than 5 GA4-linked members is **not** blocked by the Hub (it cannot see GA4 links); the
  Web tool shows the unavailable state instead.
- **Correction (found while implementing Task 17):** this does *not* prevent a one-member difference. With 6 linked
  members, one removal leaves 5 (still available), and re-querying the same past date window gives
  `6 x avg_before - 5 x avg_after` = the removed competitor's traffic. The lock only limits this to one member per 30
  days. Proposed fix, pending a decision: composition by date (a removed member keeps counting in windows that start
  before its removal). See the implementation plan's open item 5.

The 30-day value is the existing assumption in 07 §7.2 and is configurable.

### 4.8 Hardening carried from Plan A

- `EnsureOwnerHasMfa` middleware on owner-only routes (`PUT /orgs/{org}/tools/{tool}`, set delete). Plan A Open Item 3.
- Rate limiting on `/oauth/token`, the hosted login and the MFA step (reuse Plan A limiter names).

## 5. Web side (`traffic-dashboard`)

1. **Tests first.** Add `node --test` with `supertest`, and a fake Hub (a small Express stub that serves discovery, JWKS,
   token and `/hub/v1` from fixtures) so Web tests never call the real Hub.
2. **Auth.** `openid-client` authorization code + PKCE; routes `/auth/login`, `/auth/callback`, `/auth/logout`;
   `express-session` with a Postgres store; token refresh on expiry; session cookie httpOnly, Secure, SameSite=Lax.
3. **Tenant.** Remove `x-tenant-id` and `DEV_TENANT_ID`. `requireSession` loads the session, checks the selected org, maps it
   to `tenants.hub_org_id` (new unique column). First login for an org with no Web tenant: an owner confirms, the Web
   backend creates the tenant and calls `PUT /hub/v1/orgs/{org}/tools/web`. Non-owners see "ask your owner to enable".
   Org switcher when `/me/orgs` returns more than one.
4. **Competitor sets.** New table `hub_member_ga4_links(tenant_id, hub_member_id, ga4_property_id)` replaces
   `properties` rows with `role='competitor'` (the subscriber's own property stays in `properties`).
   A `hub_set_cache(tenant_id, set_id, etag, payload jsonb, fetched_at)` table caches reads (refetch on webhook or after
   5 minutes). Web UI edits call Web endpoints that forward to the Hub with the user's token.
5. **Benchmark changes.** `/api/dashboard?competitorSet=<hub set id>` averages only members with a GA4 link and requires
   >= 5; otherwise 422 with the unavailable state (no counts). The first successful benchmark per set calls the
   activation endpoint. `market`/`cuisine` modes filter GA4-linked members across the tenant's web-visible sets by
   `location_text`/`cuisine`, same threshold.
6. **Webhooks.** `POST /hooks/hub`: verify signature and timestamp, dedupe on event id, invalidate the cache entry.
   Background refetch uses a client-credentials token.
7. **Admin frontend.** `/api/admin/*` also relies on `x-tenant-id` today. It is **disabled outside local development**
   in Plan B (env guard returning 404). Staff authentication for the admin app is a separate design.
8. **Demo data.** Drop existing demo tenants and competitor properties; a seed script recreates a demo org via the Hub.

## 6. Testing

- **Hub (PHPUnit):** full OIDC code flow with PKCE against the real routes (including the MFA step and `amr`); refresh
  rotation and reuse detection; `X-Hub-Org` membership and tool-link checks (cross-org returns 404); each contract endpoint
  validated against its JSON Schema; role and scope matrix; composition lock; outbox written in the same transaction
  (rolled-back change produces no event); signature format; RLS catalog test; tenant-context tests proving no leak across
  requests and jobs on one connection.
- **Web (node:test):** login, callback, logout against the fake Hub; `x-tenant-id` has no effect; threshold 5 and
  unavailable state; webhook signature, stale timestamp and replay rejection; cache invalidation.
- **Contract drift:** the Web repo vendors `contract/v1/*.schema.json` with the Hub commit it came from; its fake-Hub
  fixtures are validated against those schemas, so a fixture that drifts from the contract fails CI.

## 7. Task tracks (for the implementation plan)

| Track | Tasks (in order) | Depends on |
|---|---|---|
| H0 Foundation debts | transaction-scoped tenant context and job middleware; remove `X-Org-Id`; default privileges and RLS catalog test; `EnsureOwnerHasMfa` | none |
| H1 Identity provider | Passport + OIDC package spike; `users.public_id`; hosted login + MFA pages; clients, scopes, claims; refresh reuse detection; discovery/JWKS | H0 |
| H2 Contract v1 | JSON Schemas; `X-Hub-Org` middleware; `/me`, `/me/orgs`, orgs, tool links; `geo_areas` + FK | H1 |
| H3 Competitor sets | tables + RLS; CRUD with roles/scopes, `If-Match`; activation + composition lock | H2 |
| H4 Events | outbox; delivery job; signing and rotation | H3 |
| W1 Web auth | test harness + fake Hub; OIDC client and sessions; remove header tenancy; tenant bootstrap; admin guard | H1, H2 (fake Hub lets it start earlier) |
| W2 Web sets | GA4 link table; cache; Hub-backed set UI; threshold 5 + activation; webhook receiver | H3, H4, W1 |

Rough size for 1-3 developers: **5-7 weeks** (H tracks about 4 weeks, W tracks about 3 weeks, partly parallel).

## 8. Open items raised by this design

1. ~~**Passport + OIDC package compatibility with Laravel 13** is unverified.~~ **Resolved by spike (2026-09-29):**
   Passport 13.8 + `jeremy379/laravel-openid-connect` 3.3 work on Laravel 13.33, and Node `openid-client` 6.8 validates
   the Hub's id_tokens. Details and the resulting changes (own userinfo endpoint, opaque access tokens, 15-minute TTL
   set explicitly, S256-only PKCE, `amr`/`auth_time` dropped) are in the implementation plan,
   `plans/2026-09-29-plan-b-hub-contract-implementation-plan.md`.
2. **Web deployment domains** must be same-site for the session cookie (section 3). Needs a hosting decision for the Web tool.
3. **Staff/admin authentication** for the Web admin frontend and for Hub support access (05 §5.6 just-in-time access) is
   unowned. Needs its own design before either admin surface goes to production.
4. **GA4 access consent.** Web competitors are GA4 properties whose owners granted the service account access. Whether that
   grant is sufficient consent for use in other subscribers' averages should go into the legal review (07 open question 5).
5. **Email verification** is not implemented in Plan A; `email_verified` will be `false` for every user until it is. Not
   needed by Plan B (no account linking), but relevant before any future tool links accounts by email.
6. Per-module Postgres schemas (decision B9) remain a later refactor.
