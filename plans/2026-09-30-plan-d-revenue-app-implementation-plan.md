# The Revenue App (Plan D) Implementation Plan

> **For agentic workers:** Execute task by task, in order within a track. Steps use checkbox (`- [ ]`) syntax for
> tracking. Every task is test-first: write the test, see it fail for the stated reason, implement, see it pass, run the
> whole suite, commit.

**Goal:** the Mosaic mock-up in `successmeter/performance-benchmarking` becomes the real Revenue app: invitation-only
accounts with MFA, onboarding, a real Overview with a labelled preview of the index cards, the upload wizard,
competitor sets, venues and team settings, all on the Hub API.

**Spec:** `plans/2026-09-30-plan-d-revenue-app-design.md` (decisions D1-D7). Also `04-ui.md`, `05-security.md` §5.3-5.4
and §5.7, and Plan C's API (`plans/2026-09-29-plan-c-sales-data-design.md` §5).

**Two repositories, two tracks:**

| Track | Repo | Branch | Tasks |
|---|---|---|---|
| A (API) | `successmeter/For-Claude-Cloud`, app in `api/` | the working branch, restarted from `main` **after Plan C merges** | 1-7 |
| F (front end) | `successmeter/performance-benchmarking` | a new branch, e.g. `claude/plan-d-app` (owner's go-ahead before the first push; **never `main`**, which Netlify publishes live) | 8-21 |

F can start before A: Tasks 8-11 need no API, and later F tasks are built against MSW mocks shaped by the API's
existing tests and schemas. Task 21 (end to end) needs both tracks.

## Verified before writing this plan (2026-09-30)

- `performance-benchmarking` at `470eb5d`: `npm ci` and `vite build` succeed on Node 22 (Vite 4, React 18, React Router
  6, Tailwind 3, Chart.js 4). No tests, no linter, no CI, no API calls. `public/_redirects` has only the SPA fallback.
- The API's `/api/login` returns `{mfa_required, mfa_token}` for MFA users and `{id}` (the **bigint** id) otherwise;
  `/api/mfa/enroll` returns the TOTP secret only (the app builds the `otpauth://` URI); `mfa.owner` answers 403 problem
  `mfa_required`. `/hub/v1` authenticates bearer tokens only, so the app needs its own competitor-set routes.

## Global Constraints

- API: everything in Plans A-C's constraints (RLS on every org-scoped table, tenant context only via
  `TenantContext::run()`, problem+json on `/api/*` Plan C routes and every new route, audit for every change).
- The user id the API exposes is `users.public_id`, never the bigint id (Plan B rule; Task 1 fixes `/api/login`).
- Front end: no tokens or secrets in the browser; every API call goes through `src/api/client.js`; no `dangerouslySetInnerHTML`
  with server data; money from integer cents, formatted once (`formatAud`).
- Real and sample data never share a card; every mock-up surface carries the Preview badge (D1).
- A task that deletes template files must leave `npm run build`, the tests and the unused-files check green.

---

## Track A: API

### Task 1: Who am I; registration switch; public ids at login

**Files:** `app/Http/Controllers/MeController.php`, `routes/api.php`, `config/hub.php` (`open_registration`),
`RegisterController`, `LoginController::establishSession`, tests.

- `GET /api/me` (`auth:sanctum`, no org header): `user {id: public_id, name, email, mfa_enabled}` and
  `orgs [{id, name, role}]` (via `TenantContext::runAsUser`, like `/hub/v1/me/orgs`).
- `/api/register` answers 404 problem `registration_closed` unless `HUB_OPEN_REGISTRATION=true`; its tests enable it.
- `establishSession` answers `{id: public_id}`.
- [ ] **Tests:** me with 0, 1, 2 orgs; roles; unauthenticated 401; registration closed/open; login response id is the
  UUID.
- [ ] **Commit** `feat: /api/me, registration switch, public ids at login`

### Task 2: Invitations table and tokens

**Files:** migration `create_invitations_table`, `app/Models/Invitation.php`, `app/Services/Invitations/InvitationTokens.php`, tests.

- Columns per design §3.1. RLS forced; unique pending invitation per (org, lower(email)).
- Token `{org_id}.{base64url(32 random bytes)}`; store `hash('sha256', secret)`; `InvitationTokens::parse()` returns
  `[orgId, secret]` or null (malformed, bad UUID).
- `DELETE` not granted: revocation sets `revoked_at`.
- [ ] **Tests:** RLS isolation; hash never equals the secret; parse rejects tampered or malformed tokens; one pending
  invitation per email per org.
- [ ] **Commit** `feat: invitations table and tokens`

### Task 3: Staff command and invitation mail

**Files:** `app/Console/Commands/HubInviteBusiness.php`, `app/Mail/InvitationMail.php` (+ view), `config/app.php`
(`frontend_url` from `APP_FRONTEND_URL`), tests.

- `hub:invite-business "<org name>" <email>`: creates the org and an owner invitation in one transaction, prints the
  link `{frontend_url}/invite/{token}` and queues the mail. Audit `org.created`, `invitation.created` (actor null).
- Mail: plain text + simple HTML, org name, role, expiry, link. Never includes the token in logs.
- [ ] **Tests:** org + invitation created; link printed once; mail queued to the address (Mail fake); invalid email
  refused.
- [ ] **Commit** `feat: invite a business from the command line`

### Task 4: Show and accept an invitation

**Files:** `app/Http/Controllers/InvitationController.php`, rate limiter `invitations` (10/min per IP), tests.

- `GET /api/invitations/{token}` -> `{org: {name}, email, role, expires_at}`; 404 problem `invitation_not_found` when
  malformed, unknown, expired, accepted or revoked (one answer for all, no oracle).
- `POST /api/invitations/{token}/accept`:
  - no account for the email: `name`, `password` (Plan A rules) -> create user, membership, mark accepted, sign in.
  - account exists: must already be signed in as that email (else 409 problem `sign_in_required`); adds membership.
  - signed in as a different email: 403 problem `invitation_email_mismatch`.
- Runs in the token's org tenant context; the membership insert and acceptance are one transaction; a second accept
  finds nothing (404).
- Response: `/api/me` shape plus `mfa_setup_required: true` for owners without MFA.
- [ ] **Tests:** every branch above; expiry; reuse; revoked; rate limit; audit rows; session regenerated.
- [ ] **Commit** `feat: accept invitations`

### Task 5: Team API

**Files:** `app/Http/Controllers/TeamController.php`, `routes/api.php`, tests.

- Routes per design §4 under `auth:sanctum` + `tenant`; writes need owner + `mfa.owner`.
- Invite: email + role (`manager` | `viewer`; owners are invited by staff or promoted); refuses an existing member or a
  pending invitation for the email (409).
- Role change / remove: the last owner cannot be demoted or removed (409 `last_owner`); owners cannot remove
  themselves if last.
- [ ] **Tests:** role matrix (manager reads, viewer 403); MFA required for owner writes; last-owner rules; RLS (another
  org's member 404); audit.
- [ ] **Commit** `feat: team members and invitations API`

### Task 6: Competitor sets for the app

**Files:** `app/Http/Controllers/AppCompetitorSetController.php` (+ members), routes, tests.

- Same behaviour as `/hub/v1` sets through `CompetitorSetService` and `CompetitorSetPayload`: If-Match / ETag,
  composition lock, tools, soft delete by owners with MFA, webhooks on change.
- [ ] **Tests:** a parity test runs the same scenarios against `/hub/v1` (bearer) and `/api` (session) and compares
  responses; payloads validate against `contract/v1/competitor-set.schema.json`; viewer read-only.
- [ ] **Commit** `feat: competitor-set routes for the Revenue app`

### Task 7: Session configuration and docs

**Files:** `config/sanctum.php`, `.env.example`, `api/README.md`, a feature test of the full cookie flow.

- `SANCTUM_STATEFUL_DOMAINS` documented for the app host (and `localhost:5173` locally); `APP_FRONTEND_URL`;
  `HUB_OPEN_REGISTRATION`; mail settings.
- [ ] **Tests:** `GET /sanctum/csrf-cookie` -> login with `X-XSRF-TOKEN` from an allowed origin -> `/api/me` works;
  a disallowed origin gets no session.
- [ ] **Commit** `feat: app session settings and docs`

## Track F: front end (`performance-benchmarking`)

### Task 8: Tooling baseline

**Files:** `package.json`, `vitest.config.js`, `src/test/setup.js`, `src/test/server.js` (MSW), `.eslintrc`,
`.github/workflows/ci.yml`, `vite.config.js` (dev proxy `/api` and `/sanctum` -> `VITE_API_ORIGIN` or
`http://127.0.0.1:8000`).

- Add Vitest, React Testing Library, jsdom, MSW, ESLint (react, hooks), `knip` (unused files and exports).
- CI: install, lint, test, build, knip on every push and pull request.
- [ ] **Tests:** one smoke test renders `<App />` at `/`.
- [ ] **Commit** `chore: tests, lint, CI and dev proxy`

### Task 9: Template clean-up (D3)

- Delete the Mosaic demo pages, partials and components listed in design §5.4, their routes and sidebar links;
  then everything `knip` reports unused. Keep Success Meter work (dashboard partials in use, Analytics, Reports,
  Recovery Roadmap, Comp Set Setup, Event Setup, Account, data and utils in use, charts in use, sign-in).
- [ ] **Checks:** build, tests, `knip` clean; a route test lists exactly the kept routes.
- [ ] **Commit** `chore: remove unused Mosaic template pages`

### Task 10: API client

**Files:** `src/api/client.js`, `src/api/errors.js`, `src/api/money.js`, `src/api/queryClient.js`, tests.

- `apiFetch(path, {method, body, orgId, headers})`: `credentials: 'include'`, JSON, `X-XSRF-TOKEN` from the cookie
  (fetching `/sanctum/csrf-cookie` first when missing, retrying once after 419), `X-Hub-Org` when `orgId` is given,
  multipart for files.
- Problem+json -> `ApiError`; a message table for known problem types (all Plan C upload codes, `mfa_required`,
  `last_owner`, `preview_stale`, ...), with a generic fallback.
- `formatAud(cents)`, `formatPct(pct)` (one decimal, sign, "-" for null).
- TanStack Query client: retries off for 4xx; 401 handled globally (Task 11).
- [ ] **Tests (MSW):** CSRF fetched once and reused; 419 retry; org header; problem mapping; multipart; formatting.
- [ ] **Commit** `feat: API client`

### Task 11: Session, guards and org switcher

**Files:** `src/auth/SessionProvider.jsx`, `RequireAuth.jsx`, `RequireRole.jsx`, `RequireMfa.jsx`,
`src/partials/Header.jsx` (org + venue switcher, real user menu and sign-out), tests.

- Loads `/api/me`; selected org in `localStorage` per user; single org auto-selected; several -> switcher; a 404 on
  the org clears it.
- `RequireAuth` -> `/signin?next=...`; `RequireMfa` sends owners without MFA to `/mfa/setup`; `RequireRole` hides
  screens by role.
- [ ] **Tests:** each redirect; org persistence; switching org refetches queries; sign-out clears state.
- [ ] **Commit** `feat: session, route guards and org switcher`

### Task 12: Sign in and MFA challenge

**Files:** `src/pages/SignIn.jsx` (replaces the template screen), `src/pages/MfaChallenge.jsx`, tests.

- Email + password -> either signed in (go to `next` or `/`) or the TOTP step (`mfa_token` kept in memory only).
- Errors: wrong credentials, rate limited (429), invalid code; no account enumeration in messages.
- Remove the sign-up link (D2); "Forgot password" hidden until reset exists (open item).
- [ ] **Tests:** both paths; each error; the token never touches storage.
- [ ] **Commit** `feat: sign in with MFA`

### Task 13: Accept invitation and MFA setup

**Files:** `src/pages/AcceptInvitation.jsx`, `src/pages/MfaSetup.jsx`, tests.

- `/invite/:token`: shows org, email and role; new account form (name, password + confirmation with the API's rules
  shown); "sign in first" path for existing accounts; clear expired/used message.
- `/mfa/setup`: enrol -> QR code (`otpauth://totp/Success%20Meter:{email}?secret=...&issuer=Success%20Meter`) and the
  secret for manual entry -> confirm code -> continue. Re-enrolment asks for the current code (API rule).
- [ ] **Tests:** each invitation state; owner lands on MFA setup; QR payload; wrong code.
- [ ] **Commit** `feat: accept invitations and set up MFA`

### Task 14: Onboarding and venues

**Files:** `src/pages/Onboarding.jsx` (replaces Onboarding01-04), `src/pages/Venues.jsx`, `src/api/venues.js`, tests.

- First sign-in with no venues -> onboarding: name, address, segment, cuisine (TripAdvisor list), time zone
  (Australian zones first), trading day ends at, GST basis -> "Upload your sales" (Task 17).
- Venues: list, add, edit (owners and managers); viewers read.
- [ ] **Tests:** validation messages from the API's 422; created venue selected; role gating.
- [ ] **Commit** `feat: onboarding and venues`

### Task 15: Real Overview and the preview wrapper (D1)

**Files:** `src/pages/Overview.jsx` (the dashboard route), `src/partials/overview/*` (LatestDayCard, TotalsCard,
RevenueChart, AverageCheckCard, AnomaliesCard, InsightsList, FreshnessBadge), `src/preview/PreviewSection.jsx`,
`src/preview/PreviewBadge.jsx`, tests.

- Real section from `/overview`, `/metrics?grain=day` and `/insights/latest`: numbers per design §5.2; average check
  per transaction = revenue / transactions over the days that have counts (hidden when none); insights listed in
  plain English from findings (one sentence template per finding type).
- Empty state (no data yet): "Upload your sales to see your performance" -> Data.
- Below: `PreviewSection` containing the existing KPI index cards, Index Trends, market comparison, roadmap and filter
  bar, unchanged in content, with the badge and "What this will show" notes.
- [ ] **Tests:** each card with fixture responses (including nulls and incomplete windows); empty venue; the preview
  badge is present on every mock card and absent from real ones.
- [ ] **Commit** `feat: real Overview with labelled preview`

### Task 16: Preview pages

- Analytics, Reports, Recovery Roadmap and Event Setup get the page-level Preview banner and a "Preview" label in the
  sidebar; the filter bar's controls apply only within the preview.
- [ ] **Tests:** banner present on each; sidebar labels.
- [ ] **Commit** `feat: label mock-up pages as preview`

### Task 17: Upload wizard and history

**Files:** `src/pages/Data.jsx`, `src/partials/uploads/*` (ChooseFile, CheckColumns, Preview, Done, History), tests.

- Step 1 choose file (CSV, 2 MB; template download link). Step 2 inspect -> column pickers with the proposal
  preselected, date format required when ambiguous, GST switch, first rows shown. Step 3 upload -> summary, problems
  table (row, column, message), changed days (paged). Step 4 commit -> done with a link to the Overview; discard
  anytime.
- Errors from every problem code have plain messages; `preview_stale` offers "check again" with the same file.
- History: runs with status, counts and dates.
- [ ] **Tests:** the whole wizard with MSW, including an ambiguous date file, problems blocking commit, stale preview,
  infected file, 429.
- [ ] **Commit** `feat: sales upload wizard`

### Task 18: Competitor sets

**Files:** `src/pages/CompetitorSets.jsx` (replaces Comp Set Setup), `src/api/competitorSets.js`, tests.

- List, create (name, tools Revenue/Web), rename, delete (owner), members add/edit/remove with ETag/If-Match; 412 ->
  "someone else changed this set - reloaded"; composition-lock notice with the unlock date.
- [ ] **Tests:** each action; 412 flow; lock notice; viewer read-only.
- [ ] **Commit** `feat: competitor sets`

### Task 19: Settings: account and team

**Files:** `src/pages/settings/Account.jsx`, `src/pages/settings/Team.jsx`, tests; delete template settings pages
not used (Apps, Billing, Feedback, Notifications, Plans).

- Account: name, email, MFA status with "set up" / "reset" (re-enrol).
- Team (owners manage, managers read): members with roles, pending invitations (revoke), invite form; last-owner
  message.
- [ ] **Tests:** role gating; invite and revoke; last-owner error shown.
- [ ] **Commit** `feat: account and team settings`

### Task 20: Hosting files

**Files:** `scripts/write-redirects.mjs` (build step), `public/_headers`, `netlify.toml`, `README.md`.

- Build writes `dist/_redirects`: `/api/*` and `/sanctum/*` -> `${VITE_API_ORIGIN}/:splat 200` (fails the build if
  `VITE_API_ORIGIN` is unset in production), then `/* /index.html 200`.
- `_headers`: CSP (`default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'` only if Tailwind output
  needs it; `frame-ancestors 'none'`), `Referrer-Policy`, `X-Content-Type-Options`, `Permissions-Policy`.
- README: local setup with the API, env vars, deploy notes, and the rule that `main` publishes live.
- [ ] **Tests:** the script's output for set/unset origin.
- [ ] **Commit** `chore: Netlify proxy and security headers`

### Task 21: End-to-end check and docs

- Playwright against the real API (`artisan serve`, `queue:work`) and `vite preview` with the proxy:
  `hub:invite-business` -> open link -> create account -> MFA setup -> onboarding -> upload a two-month CSV ->
  Overview numbers match the API -> preview badge visible -> invite a viewer -> viewer signs in, sees Overview, no Data
  -> create a competitor set -> sign out.
- [x] Update 04-ui.md (what is real vs preview), 07 (Plan D decisions and open items), this plan's implementation
  notes, both READMEs.
- [x] **Commit** in each repo: `docs: Plan D setup and end-to-end check`

---

## Self-review

- **Spec coverage.** D1 -> Tasks 15-16; D2 -> Tasks 1-5, 12-13; D3 -> Tasks 9, 19; D4 -> Tasks 6, 18; D5 -> Tasks 7-8,
  20; D6 -> Tasks 8, 10; D7 -> Tasks 8, 21. Design §3 -> Tasks 1-5, 11-13; §4 -> Tasks 1-7; §5 -> Tasks 8-20; §6 ->
  Tasks 2, 4, 10, 20; §7 -> each task's tests and Task 21.
- **Riskiest parts:** the invitation acceptance paths (Task 4: existing accounts, email mismatch, reuse) and the upload
  wizard's many states (Task 17). Both have explicit branch-by-branch tests.
- **Not in this plan:** password reset (needs mail in production; listed below), POS connections, benchmarks, AI,
  staff screens.

## Open Items (raised by this plan)

1. **Plan C (successmeter/For-Claude-Cloud#2) merges before Track A starts.**
2. **Password reset** is not in Plan D; until it exists, a user who forgets their password is re-invited by an owner or
   staff. Add it once production mail exists.
3. **Where the real app is published** (replace the mock-up on `main`, or a new Netlify site first) and **the API host**
   (the proxy target) are needed before anyone outside can use it.
4. **Production mail provider** for invitations.
5. **Repo visibility:** `For-Claude-Cloud` is public; make it private once cloud sessions no longer need it.

## Implementation notes (2026-09-30)

All 21 tasks are done: Track A on `claude/sleepy-fermat-jc39ms` (API suite 380 tests), Track F on
`performance-benchmarking` branch `claude/plan-d-app` (120 unit/component tests, lint, knip, build in CI) plus the
Playwright journey (`npm run e2e`), which passed twice in a row against the real API.

Changes from the plan, and things found on the way:

- **Transactions in the daily series.** The read API had no transaction counts, so "average check per transaction"
  couldn't be computed; `/overview` series and `/metrics?grain=day` now carry `transactions` (null when not uploaded).
- **Sign-out bug (Plan A).** `/api/logout` called `Auth::logout()`, which inside `auth:sanctum` is Sanctum's request
  guard: every cookie sign-out answered 500 and the session stayed valid. Found by the e2e check; fixed with a test.
- **Invitation links in development.** `/invite/{org}.{secret}` has a dot, which Vite's dev/preview servers treat as a
  file; a small Vite plugin routes `/invite/*` to the app (Netlify's fallback was never affected).
- **Build-time safety.** The Netlify build fails without `VITE_API_ORIGIN`, so merging to `main` before the API is
  hosted leaves the live site as it was. `vite.config.js` no longer injects the whole build environment
  (`define: process.env`) into the bundle.
- **CSP.** Strict policy in `public/_headers`, checked in Chromium against the built app; the inline theme/sidebar
  scripts moved to files, the unused Google Maps script was removed, Google Fonts and the (preview) ABN lookup allowed.
- **Onboarding** has no Places autocomplete; the address is free text (the venue API resolves the market later).
- **Team:** owners are added by inviting a manager and promoting them (owners can't be invited directly from the app).
- **Competitor sets** moved from Settings -> Comp Set Setup to their own page (`/settings/compset` redirects).
