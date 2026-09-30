# Plan D Design: The Revenue App (Mosaic front end on the Hub API)

**Date:** 2026-09-30
**Status:** Draft for review
**Scope:** turn `successmeter/performance-benchmarking` (the Mosaic React mock-up on Netlify) into the real Revenue app on
top of the Hub API: invitation-only accounts, sign-in with MFA, onboarding, a real Overview, the sales upload wizard,
competitor sets, venues and team settings. Mock-up features that have no data yet stay visible as a labelled preview.
This is 04-ui.md for Phase 1, and the last piece of Phase 1's "internal demo" milestone (06 §6.5).

Builds on Plans A (auth, MFA, orgs, venues, RLS), B (tenant header, problem responses, competitor sets) and C (venue
API, uploads, metrics, insights). **Plan C must merge first** (its API is what this app calls).

---

## 1. Decisions

| # | Decision | Source |
|---|---|---|
| D1 | The dashboard shows **real data where it exists** (revenue, week and year comparisons, 7/28-day totals, average check per transaction, trends, anomalies, freshness) and keeps the mock-up's index cards (Occupancy/MPI, Avg Check/ACI, RevPAS/RGI, market comparison, index trends, recovery roadmap) as a **labelled preview on sample data** until that data exists. | Asked 2026-09-30 |
| D2 | **Invitation-only accounts** for the pilot. Staff create a business and invite its owner; owners invite their team. Public sign-up is switched off. | Asked 2026-09-30 |
| D3 | **Remove unused Mosaic template pages** and components; keep only Success Meter screens and the building blocks they use. | Asked 2026-09-30 |
| D4 | **Competitor sets are edited in this app** too (shared with the Web tool through the Hub). | Asked 2026-09-30 |
| D5 | The app stays a **separate repository and Netlify site**. The browser reaches the API **through a Netlify proxy** (`/api/*` rewritten to the API host), so the app and API are the same origin: Sanctum's cookie session works without a shared custom domain and without CORS. | Default (removes the same-site hosting blocker) |
| D6 | Keep React 18, React Router 6, Tailwind 3 and Chart.js from the mock-up; add **TanStack Query** (04 §4.1) and a QR-code library for MFA enrolment. No state-management library. | Default |
| D7 | Tests: **Vitest + React Testing Library** with **MSW** for the API in unit/component tests; **Playwright** end to end against the real Laravel API (as in Plan B). | Default |

## 2. What exists today

**Front end** (`performance-benchmarking`, Vite + React 18 + Tailwind 3, deployed from `main` to
`successmeter-performance-benchmarking.netlify.app`): the Mosaic template (~70 routes) plus Success Meter work on top of
it: a dashboard with KPI index cards, an Index Trends deep-dive, market comparison, a recovery roadmap card, a filter bar
(service period, restaurant type, cuisine, segment, suburb, comp set, period, day analysis), Analytics and Reports
pages, Comp Set Setup and Event Setup settings pages, TripAdvisor taxonomy, weather and event definitions, and mock
data. Sign-in, sign-up and onboarding pages are template screens that call nothing. **There is no API call anywhere.**

**Back end** (`For-Claude-Cloud/api`): `/api/login` (returns `mfa_required` + `mfa_token` for MFA users),
`/api/mfa/verify|enroll|confirm`, `/api/logout`, `/api/register` (creates an org and its owner), Sanctum cookie
sessions, `tenant` middleware on `X-Hub-Org`, and Plan C's venue, upload, overview, metrics and insights routes.
Missing for an app: who am I and which orgs am I in, invitations, team management, and competitor-set routes a
cookie-session user can call (`/hub/v1` authenticates bearer tokens only).

## 3. Accounts and access (D2)

### 3.1 Invitations

New table `invitations` (RLS, org-scoped): `id`, `org_id`, `email`, `role` (owner/manager/viewer), `token_hash`,
`invited_by` (nullable for staff), `expires_at` (7 days), `accepted_at`, `revoked_at`, timestamps.

- **Token:** `{org_id}.{random 32 bytes, base64url}`; only the SHA-256 of the random part is stored. The org id prefix
  lets the unauthenticated acceptance request run inside that org's tenant context and find the row under RLS (the
  same "no cross-org lookup" rule Plan C's clean-up followed).
- **Staff create a business:** `php artisan hub:invite-business "<org name>" <owner email>` creates the org and an owner
  invitation and prints the link (and emails it when mail is configured). This is the pilot's "admin screen" until
  staff authentication exists (07 §7.4).
- **Owners invite their team** from Settings -> Team: email + role (manager or viewer). Owners can revoke pending
  invitations, change a member's role and remove members; the last owner cannot be removed or demoted.
- **Accepting:** `GET /api/invitations/{token}` shows org name, email and role (404 when expired, used or revoked);
  `POST /api/invitations/{token}/accept` with name and password creates the user (or, if the email already has an
  account, requires signing in as that user first and adds the membership), marks the invitation accepted and signs
  in. Audit: `invitation.created|accepted|revoked`, `membership.role_changed|removed`.
- **Owners must enrol MFA** before anything else (Plan A decision; `mfa.owner` exists). After accepting, an owner is
  taken straight to MFA enrolment; the app blocks other screens until it is done. Managers and viewers are offered MFA
  but not forced.
- `/api/register` is disabled unless `HUB_OPEN_REGISTRATION=true` (off by default; tests keep covering it).
- **Mail:** Laravel mail with the `log` driver locally; production needs an SMTP/SES provider (open item). Links point
  at `APP_FRONTEND_URL`.

### 3.2 Session and org selection

- `GET /api/me`: `{ user: {id (public_id), name, email, mfa_enabled}, orgs: [{id, name, role}] }`. Needs sign-in, no
  org header.
- The app keeps the selected org id in `localStorage` (per user) and sends it as `X-Hub-Org` on every tenant call. One
  org is selected automatically; several show an org switcher in the header. A 404 on the org (membership removed)
  clears the selection.
- Sanctum SPA flow: `GET /sanctum/csrf-cookie`, then JSON requests with `X-XSRF-TOKEN` from the cookie; session cookie
  httpOnly, `SameSite=Lax`, `Secure` in production.

## 4. API additions (For-Claude-Cloud)

| Method and path | Who | Purpose |
|---|---|---|
| `GET /api/me` | signed in | user and org memberships |
| `GET /api/invitations/{token}`, `POST .../accept` | anyone with the link | see and accept an invitation |
| `GET /api/team` | owner, manager | members and pending invitations |
| `POST /api/team/invitations`, `DELETE /api/team/invitations/{id}` | owner (+MFA) | invite, revoke |
| `PATCH /api/team/members/{user}`, `DELETE /api/team/members/{user}` | owner (+MFA) | change role, remove |
| `GET/POST /api/competitor-sets`, `GET/PATCH/DELETE /api/competitor-sets/{set}` | per Plan B roles | sets for this app |
| `POST/PATCH/DELETE /api/competitor-sets/{set}/members[/{member}]` | per Plan B roles | members |

Competitor-set routes are thin controllers over Plan B's `CompetitorSetService` and payload, so the rules (If-Match,
composition lock, tools visibility, webhooks to the Web tool) are the same code, not a copy. Responses reuse the
contract v1 set schema.

Config: `SANCTUM_STATEFUL_DOMAINS` includes the app's host; `SESSION_DOMAIN` unset (host-only cookie on the app's
origin, because the proxy makes the API same-origin); `APP_FRONTEND_URL` for links.

## 5. Front end

### 5.1 Structure

```
src/
  api/          client.js (fetch, CSRF, X-Hub-Org, problem+json -> ApiError), queries per resource (TanStack Query)
  auth/         SessionProvider (me, selected org), RequireAuth, RequireRole, RequireMfa
  pages/        SignIn, MfaChallenge, AcceptInvitation, MfaSetup, Onboarding, Overview, Uploads (wizard + history),
                CompetitorSets, Venues, Settings (Account, Team), Preview pages (Analytics, Reports, Recovery Roadmap)
  partials/     Sidebar, Header (org + venue switchers), dashboard cards (real and preview)
  preview/      PreviewBadge, PreviewSection, the mock data and taxonomies (moved from src/data)
```

### 5.2 Screens

| Screen | Content | Data |
|---|---|---|
| **Sign in** | email + password; then a TOTP step when required | `/api/login`, `/api/mfa/verify` |
| **Accept invitation** | org name, set name and password (new users) | invitations API |
| **MFA setup** | QR code from the enrolment secret, confirm with a code | `/api/mfa/enroll`, `/api/mfa/confirm` |
| **Onboarding** (first venue) | venue name, address, segment, cuisine, time zone, trading-day end, GST basis -> first upload | venues API |
| **Overview** (dashboard) | latest day, 7- and 28-day totals with WoW/YoY, 90-day revenue chart with last year, average check per transaction when counts exist, anomalies, insights list, freshness badge; then a **Preview** section with the mock index cards | overview, metrics, insights |
| **Data (uploads)** | template download; wizard: choose file -> check columns (proposed mapping, date format when ambiguous, GST) -> preview (new/changed/unchanged, problems by row, changed days) -> commit; history of uploads | Plan C upload API |
| **Competitor sets** | list, create, rename, tools (Revenue/Web), members add/edit/remove, lock notice | new comp-set routes |
| **Venues** | list and edit venues; add a venue | venues API |
| **Settings -> Account** | name, password change link, MFA status | `/api/me` |
| **Settings -> Team** | members and roles, pending invitations, invite | team API |
| **Analytics, Reports, Recovery Roadmap** | kept as **Preview** pages (mock-up) | sample data |

Role rules follow the API (04 §4.1): viewers see Overview, Preview pages and Competitor sets read-only; they do not see
Data or Team. The UI hides what the API would refuse, and handles a 403 anyway.

### 5.3 The preview (D1)

- Every mock card and page is wrapped in a `PreviewSection`: a visible **"Preview - sample data"** badge, a one-line
  "What this will show" note, muted styling. Numbers never mix with real ones in the same card.
- The filter bar's controls that have no data yet (service period, restaurant type, cuisine, segment, suburb, comp set)
  apply only inside the preview; the real Overview has its own venue and date-range controls.
- "Average check" on the real Overview is **per transaction** (revenue / transactions), labelled as such; the preview's
  ACI is per cover. They are different measures and are named differently.
- Event Setup and weather filters stay preview-only.

### 5.4 Template clean-up (D3)

Delete the Mosaic demo areas (e-commerce, community, finance, jobs, tasks, inbox, messages, calendar, campaigns,
fintech, component showcase, utility pages, sign-up and extra onboarding templates) and components/charts nothing
imports afterwards. Verified by a build plus an "unused files" check in CI. Kept: the shell (Sidebar, Header, theme
toggle), cards, charts and form components the Success Meter screens use, and all Success Meter additions.

### 5.5 API client

- `fetch` with `credentials: 'include'`; CSRF cookie fetched once per session and after a 419.
- Problem+json errors become `ApiError {status, type, title, extra}`; screens map known types to plain-English
  messages (e.g. `preview_stale` -> "Your sales changed since this preview - upload the file again").
- 401 -> back to sign-in (keeping the intended path); 403 -> "You don't have access"; 404 on the org -> org chooser.
- Money is shown in AUD from integer cents; dates in the venue's business days.

### 5.6 Hosting (D5)

- `public/_redirects`: `/api/*  https://<api host>/api/:splat  200` and `/sanctum/*` likewise, before the SPA
  fallback `/* /index.html 200`. The API host is a build-time setting (`VITE_API_ORIGIN`, used to write
  `_redirects`).
- Local development uses Vite's dev-server proxy the same way (`/api` -> `http://127.0.0.1:8000`).
- Until the API is hosted, `main` must not auto-publish the real app over the mock-up: the plan's deploy step either
  keeps Netlify locked or publishes to a new site/branch deploy (open item).

## 6. Security

- No tokens in the browser: Sanctum httpOnly session cookie + CSRF (04 §4.1).
- Invitation tokens: high-entropy, hashed at rest, single use, 7-day expiry, revocable, rate-limited acceptance.
- Owner MFA enforced at the API (`mfa.owner`) and in the UI flow.
- Content Security Policy and security headers on Netlify (`_headers`): `default-src 'self'`, no inline scripts,
  `frame-ancestors 'none'`.
- The UI never renders server problem text as HTML.

## 7. Testing

- API: feature tests for `/api/me`, invitations (expiry, reuse, revoke, existing-account path, RLS), team role rules
  (last owner), comp-set routes (parity with `/hub/v1` behaviour), registration flag.
- Front end: component tests with MSW for each screen's states (loading, empty, error, success, forbidden); the upload
  wizard through every step, including problems and `preview_stale`.
- End to end (Playwright, real API): invitation -> MFA setup -> onboarding -> upload -> Overview shows the numbers ->
  invite a viewer -> viewer sees Overview, no Data -> competitor set create -> sign out.

## 8. Out of scope

Benchmarks against other venues (Phase 3), AI strategy (Phase 4), POS connections (Phase 2; the Data screen leaves room
for them), staff/admin screens, NZ, billing.

## 9. Open items

1. **Plan C must merge** before Plan D's API work (its routes are the app's data).
2. **API hosting** (PHP host + Postgres) is still undecided; the proxy needs its URL. Until then the real app can only run
   locally or on a preview deploy.
3. **Production mail provider** for invitation emails.
4. **Netlify publishing:** decide whether the real app replaces the mock-up site on `main` or goes to a new site first.
5. The preview's per-cover and per-seat measures need covers, seats and opening hours; collecting them is a later
   decision (06 §6.4).
