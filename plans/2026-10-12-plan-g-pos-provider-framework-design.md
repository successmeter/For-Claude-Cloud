# Plan G: POS provider framework, and a Shiji Infrasys placeholder (design)

**Goal:** generalize the Square-specific POS integration built in Plan E into a provider-agnostic
framework, so a second POS system can be added without re-doing the sync engine, schema, or
dispatch layer — and register a placeholder for Shiji Infrasys (a POS used by restaurants in
leading five-star hotels) so the integration point exists in the codebase ahead of real API access.

Builds on Plan E (`2026-10-01-plan-e-square-covers-design.md`, the Square connector, `pos_connections`,
`pos_location_links`, the nightly sync job) and 03-integrations.md §3.1 (the original connector
interface concept from Plan A, which Plan E implemented concretely for Square only).

## 1. Decisions (asked 2026-10-12)

| # | Decision |
|---|---|
| G1 | **Shiji's real API is out of scope for this plan.** Shiji Infrasys requires a Business/Operations Team endorsement before a Developer Account, then sandbox API keys, then production certification (confirmed against `developer.hero-cloud.com`) — there is no public API reference and no credentials exist yet. This plan builds the generic framework and a stub only; wiring Shiji's real calls is a later plan once sandbox access exists. |
| G2 | **Square is refactored to implement the new interface**, not left as a one-off. This proves the abstraction against a real, working provider instead of leaving it unvalidated until Shiji's real implementation arrives, and makes Square the reference implementation for every POS added after this. |
| G3 | **The connection/authentication flow is *not* unified.** Square's OAuth redirect flow (`SquareConnectionController`) stays provider-specific. Shiji's real auth mechanism is unknown (could be API keys, client-credentials OAuth, or something else) — forcing a shared shape now would be speculative generality likely to be wrong. Each provider gets its own connection controller; everything downstream of "a connection exists" (locations, sync, status) is generalized. |
| G4 | **Shiji is backend-only for now.** It exists in the provider registry and the schema's allowed values, but is not registered to any route and does not appear in the Data sources UI, since there is nothing a subscriber could do with it yet. |

## 2. What's generalized vs. what stays per-provider

Reading Plan E's actual Square code (`app/Pos/Square/*`, `app/Http/Controllers/Pos/*`) shows the
split is not even — most of it is already provider-agnostic in behavior, just hardcoded to the
string `'square'`:

| Already generic in behavior today | Genuinely provider-specific |
|---|---|
| Sync window math (backfill vs. 7-day re-pull, business-day cutoff) | How you connect: OAuth redirect (Square) vs. unknown (Shiji) |
| Writing `sales_daily`, `sales_daily_categories`, `sales_daily_revisions`, triggering recompute | Token refresh mechanics (Square-specific; an API-key provider has none) |
| The queued job's transaction/savepoint, circuit breaker, failure bookkeeping | Fetching raw locations, catalog/categories, and orders from the provider's API |
| The nightly dispatch loop (`pos:sync`) | |
| Listing locations to link, and the "sync now" trigger (`SquareLocationsController`, `SquareSyncController` — read directly, neither has OAuth-specific logic) | |

## 3. Interface

```php
namespace App\Pos;

interface PosProvider
{
    public function key(): string;                              // 'square', 'shiji'
    public function available(): bool;                           // configured on this Hub at all
    public function clientFor(object $connection): PosProviderClient;
}

interface PosProviderClient
{
    /** @return list<array{id: string, name: string, timezone: ?string, status: ?string}> */
    public function locations(): array;

    public function dayTotals(object $link, CarbonImmutable $from, CarbonImmutable $to): DayTotals;
}
```

`DayTotals` (currently `App\Pos\Square\DayTotals`) moves to `App\Pos\DayTotals` — it's already
provider-agnostic (a day/category aggregation value object); only the thing that fills it was
Square-specific.

### `SquarePosProvider` / `SquareProviderClient`

Thin wrappers: `SquareProviderClient::locations()` delegates to the existing `SquareClient::locations()`;
`dayTotals()` wraps the windowing/chunking logic currently inlined in `SquareSync::run()` (the loop
that chunks by month and calls `$client->completedOrders(...)`, feeding a `DayTotals` via
`SquareCatalog`). No behavior change — this is extraction, not rewrite. `SquareClient`,
`SquareCatalog`, `SquareOAuth`, and `SquareConnectionController` are untouched in shape.

### `ShijiPosProvider` (new, stub)

```php
final class ShijiPosProvider implements PosProvider
{
    public function key(): string { return 'shiji'; }
    public function available(): bool { return false; } // no credentials configured; nothing to connect to
    public function clientFor(object $connection): PosProviderClient
    {
        throw new \RuntimeException('Shiji integration is pending sandbox API access (see Plan G decision G1).');
    }
}
```
Registered in the provider registry (so `pos_connections.provider = 'shiji'` is a valid, queryable
state and the framework is provably pluggable) but never reachable from a route — see §5.

## 4. Sync engine and job

`SquareSync::run()`'s window computation and `write()` (the DB-write/revision logic) move to a
provider-agnostic `PosSync` class (not to be confused with the existing `PosSync` *console
command*, which is renamed `QueuePosSyncs` to free the name) taking a `PosProviderClient` and the
`pos_location_links` row, instead of a concrete `SquareClient`. Behavior is identical; only the
type hint changes from `SquareClient` to `PosProviderClient`.

`SyncSquareLocation` (queued job) becomes `SyncPosLocation`: resolves the connection's `provider`
column, looks up the matching `PosProvider` from the registry, and calls `clientFor($connection)`
instead of hardcoding `SquareClient::forOrg()`. The transaction/savepoint, circuit breaker (5
consecutive failures → pause), and auth-failure → `needs_reauth` handling are unchanged — those
already only depend on generic exception shapes (`PosAuthFailed`, `PosRequestFailed`,
`PosUnavailable`, renamed from their Square-specific equivalents, with `Square*` versions becoming
thin subclasses so existing `catch` sites and tests keep working).

The nightly command (`QueuePosSyncs`, was `PosSync`) is otherwise unchanged — it already loops
`pos_orgs`/`pos_location_links`/`pos_connections` generically and only needs the job class rename.

## 5. Controllers and routing

`SquareLocationsController` → `PosLocationsController`, `SquareSyncController` → `PosSyncController`:
both already contain no OAuth-specific logic (confirmed by reading them directly) — only
`SquareClient::forOrg()` becomes "resolve the connection for `{provider}`, then
`$registry->get($provider)->clientFor($connection)`". `PosStatusController` loops every
**registered and routable** provider instead of querying `'square'` alone.

Routes gain a `{provider}` segment: `GET /pos/{provider}/locations`, `POST
/pos/{provider}/locations/{location}`, `POST /pos/{provider}/sync`. Only `square` is bound in
`routes/api.php` for now — Shiji exists in the registry and the database CHECK constraint, but
registering its routes (and writing `ShijiConnectionController`) is explicitly deferred to the plan
that has real API access (decision G1). `SquareConnectionController`'s own routes
(`/pos/square/connect`, `/pos/square/callback`, `/pos/square/disconnect`) are unchanged — connection
flow stays per-provider per decision G3.

## 6. Schema

One migration. The original CHECK was inline in the `CREATE TABLE` (no explicit constraint name), so
Postgres auto-generated one — the implementer must confirm its real name against the actual schema
(`\d pos_connections` or `information_schema.check_constraints`) rather than assume the conventional
`pos_connections_provider_check`:
```sql
ALTER TABLE pos_connections DROP CONSTRAINT <actual_name_from_information_schema>;
ALTER TABLE pos_connections ADD CONSTRAINT pos_connections_provider_check CHECK (provider IN ('square', 'shiji'));
ALTER TABLE pos_connections ALTER COLUMN scopes DROP NOT NULL;
```
`scopes` becomes nullable: it's an OAuth concept (space-separated granted scopes); an API-key-based
provider has nothing to put there. No other schema change — `access_token_enc` doubles as "the
credential, however shaped" for both OAuth access tokens and a future flat API key; `refresh_token_enc`
and `token_expires_at` are already nullable and simply stay null for a provider with no refresh
concept.

## 7. File structure

```
api/app/Pos/
  PosProvider.php                    (new interface)
  PosProviderClient.php              (new interface)
  PosProviderRegistry.php            (new: key -> PosProvider, app-bound)
  DayTotals.php                      (moved from Pos/Square/, unchanged)
  PosSync.php                        (new: generic window/write logic, extracted from Square/SquareSync.php)
  PosAuthFailed.php, PosRequestFailed.php, PosUnavailable.php   (new, generic; Square's become subclasses)
  CategoryGuesser.php, OAuthState.php, SplitDeriver.php         (unchanged)
  Square/
    SquarePosProvider.php            (new, thin)
    SquareProviderClient.php         (new, thin)
    SquareClient.php, SquareCatalog.php, SquareOAuth.php        (unchanged)
    SquareAuthFailed.php, SquareRequestFailed.php, SquareUnavailable.php   (become subclasses of the generic ones)
  Shiji/
    ShijiPosProvider.php             (new, stub per §3)
api/app/Jobs/
  SyncPosLocation.php                 (renamed from Pos/Square/SyncSquareLocation.php, generalized)
api/app/Console/Commands/
  QueuePosSyncs.php                   (renamed from PosSync.php, job class reference updated)
api/app/Http/Controllers/Pos/
  PosLocationsController.php          (renamed/generalized from SquareLocationsController.php)
  PosSyncController.php               (renamed/generalized from SquareSyncController.php)
  PosStatusController.php             (generalized: loops registered providers)
  SquareConnectionController.php      (unchanged, per decision G3)
api/database/migrations/
  2026_10_12_xxxxxx_widen_pos_provider_and_nullable_scopes.php
```

## 8. Testing

Existing Square tests (`SquareClientTest`, `SquareConnectTest`, `SquareLocationsTest`,
`SquareSyncTest`, `tests/Support/SquareFixtures.php`) move/adapt to exercise the renamed classes —
same assertions, since behavior is unchanged by the extraction. New tests: `PosProviderRegistryTest`
(resolves `square`, resolves `shiji` as unavailable, unknown key throws), and a test proving
`ShijiPosProvider::clientFor()` throws rather than silently no-opping. No test depends on Shiji's
real API, since none exists yet.

## 9. Open items

- **Shiji's real connector** (auth, client, provider registration, routes) is explicitly out of
  scope — tracked here as the next step once Business/Ops endorsement and sandbox credentials exist.
- **`access_token_enc` as a generic credential slot** is a naming compromise (keeps the migration to
  one column-nullability change instead of a rename). If a third provider's auth shape doesn't fit
  "one encrypted blob," revisit the column name/shape then — not needed for Shiji's stub today.
