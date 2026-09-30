<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Database connections (dual-connection setup)

This app uses two Postgres connections, defined in `config/database.php`:

- `pgsql` — the privileged Sail/superuser connection. Migrations always run
  against this connection, explicitly: `php artisan migrate --database=pgsql`
  (never the bare `php artisan migrate`, whose default connection is `pgsql_app`
  once `DB_CONNECTION=pgsql_app` is set for runtime — see below).
- `pgsql_app` — the restricted, non-owner `app_user` role the application runs
  as at runtime, subject to Postgres row-level security (RLS) policies on
  tenant tables. `app_user` deliberately lacks `CREATE`/`ALTER` privileges, so
  it cannot run migrations (and, as a table owner would, silently bypass RLS).

If you add a migration or run `composer setup`/`composer create-project`,
double-check any `artisan migrate` invocation includes `--database=pgsql`. See
`plans/2026-09-23-foundation-implementation-plan.md` (Task 3, RLS/`app_user`
setup) for the full rationale.

## Hub: sign-in provider and contract for other tools (Plan B)

The Hub module (`app/Hub`) is the OpenID Connect provider and API that other tools (the Web
Performance tool first) use for sign-in, organisations and shared competitor sets. Design and plan:
`plans/2026-09-29-plan-b-hub-contract-design.md` and `-implementation-plan.md`.

- **Keys and issuer.** Locally, `php artisan passport:keys`. In production, supply
  `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` from the secrets manager. Set `HUB_ISSUER` to the
  Hub's public URL (it must match what clients discover); `OPENID_FORCE_HTTPS=false` only for a
  plain-http local Hub.
- **Register a tool:** `php artisan hub:client <tool> <redirect-uri> <post-logout-uri>` prints its
  client id and secret; `php artisan hub:webhook-endpoint <tool> <url>` prints its webhook signing
  secret (shown once).
- **Run:** besides `php artisan serve`, webhooks need `php artisan queue:work` and the scheduler
  (`php artisan schedule:work` locally), which runs `hub:deliver-webhooks` every minute.
- **Contract.** JSON Schemas for every `/hub/v1` response and webhook are in `contract/v1`
  (see its README). Consumers vendor a copy and record the source commit; change the schemas
  here first, then re-vendor.

## Sales uploads, metrics and insights (Plan C)

Venues upload daily sales totals by CSV; the API gives back the venue's own performance.
Design and plan: `plans/2026-09-29-plan-c-sales-data-design.md` and `-implementation-plan.md`.

- **Flow:** `POST /api/venues/{venue}/uploads/inspect` proposes a column mapping and stores
  nothing; `POST /api/venues/{venue}/uploads` with the file and the confirmed mapping returns a
  preview (new, changed, unchanged days, and problems by row); `POST /api/uploads/{run}/commit`
  applies it. The template is `GET /api/uploads/template.csv` (header only).
- **Limits:** CSV only, UTF-8, 2 MB, 5,000 rows, 20 uploads per user per hour
  (`config/ingest.php`). Any problem row blocks the commit; the user fixes the file and uploads
  again.
- **Malware scanning:** `INGEST_SCANNER=clamav` (the default) needs clamd at `CLAMD_ADDRESS`
  (`unix:///path` or `tcp://host:port`); if clamd cannot answer, uploads are refused.
  `INGEST_SCANNER=none` is for local development and tests only and refuses to start elsewhere.
- **Snapshots:** only the mapped columns of each committed upload are kept, envelope-encrypted, on
  the `snapshots` disk (`SNAPSHOTS_ROOT`; production needs AU-region object storage), for 90 days.
  The original file is never stored.
- **Scheduler:** `ingest:expire-runs` (every 15 minutes) expires previews not committed within 24
  hours; `ingest:prune-snapshots` (daily, 03:00) deletes snapshots past retention.
- **Read API:** `GET /api/venues/{venue}/overview`, `/metrics?grain=day|week|month&from=&to=`
  and `/insights/latest` (findings follow `schemas/findings.v1.json`). Money is GST-inclusive
  cents; a day without data is `null`, never zero.

## Deploying (AWS Sydney)

The container image is `api/Dockerfile` (roles `web`, `worker`, `scheduler`, `migrate`); the infrastructure is
`infra/` (AWS CDK). Step-by-step: `docs/hosting-aws.md`. Migrations work on managed Postgres, where the owner is not a
superuser, and create `app_user` with `DB_APP_PASSWORD`.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
