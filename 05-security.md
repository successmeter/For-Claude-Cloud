# 5. Security and Encryption

## 5.1 Encryption in transit

- TLS 1.2+ everywhere (1.3 preferred), HSTS, and TLS required on DB, cache and queue connections.
- Inter-tool webhooks are HMAC-signed with timestamps to prevent replay.
- Outbound calls to POS vendors, the intermediary and AI providers are TLS only.

## 5.2 Encryption at rest

- **Storage layer:** database, backups, object storage and queues are encrypted with KMS-managed keys.
- **Application-level envelope encryption** with a per-org data key (wrapped by KMS) protects: POS credentials
  and tokens, uploaded files and source snapshots, and personal fields (names, emails). Deleting an org
  destroys its key (crypto-shredding), which renders its backups unreadable.
- **Decision (accepted):** `sales_daily` values are protected at the storage layer only, not per-value
  encrypted, because the insight engine, rollups and peer-pool jobs are SQL over those numbers. This meets
  "encrypted at rest and in transit" but is not end-to-end tenant-key encryption of sales figures. Moving to that
  would require moving the statistics layer out of SQL, which is materially more work.

## 5.3 Authentication

- Argon2id passwords, breached-password check, rate limiting.
- **Mandatory TOTP MFA for owners**; passkeys (WebAuthn) later.
- SPA: Sanctum httpOnly, SameSite cookies with session rotation.
- OIDC: 15-minute access tokens, rotating refresh tokens.
- Service-to-service: OAuth client-credentials with per-tool scopes; secrets in a secrets manager with rotation.

## 5.4 Authorisation and tenant isolation

- Laravel policies by role (owner / manager / viewer) at org and venue level.
- Postgres **row-level security** as a second layer; the app DB role cannot bypass it.
- Every queued job carries its `org_id` and sets tenant context. Cache keys and object-storage prefixes are per org,
  enforced by IAM conditions. Logs redact sensitive fields.
- **Peer pool isolation:** separate schema and DB role, no org or venue columns, and DB `CHECK` constraints
  enforcing participants >= 5 (and the distinct-org rules), so below-threshold data cannot be stored even if code has a bug.

## 5.5 Privacy and data minimisation

- The normaliser keeps only whitelisted revenue fields. Source snapshots are PII-stripped, encrypted,
  access-restricted and kept 90 days.
- Contribution consent is explicit, versioned and timestamped, and must cover use in competitor-set aggregates.
- Australian Privacy Act 1988 (APPs) and the Notifiable Data Breaches scheme apply where personal information is held
  (users, and sole-trader subscribers). **Legal review is required before launch; this document is not legal advice.**
- Subscriber rights: export and deletion of their data.

## 5.6 Audit

Append-only log of logins, MFA changes, connection changes, consent changes, exports, AI generations and staff access
to tenant data. Staff access to production tenant data is just-in-time and approved; no standing access.

## 5.7 Application hardening

CSP, CSRF protection, input validation, dependency and secret scanning in CI, SAST, upload safety (type/size limits,
malware scan, CSV formula-injection escaping), and SSRF protection on any connector-supplied URLs.

## 5.8 Backup and recovery

Encrypted backups with point-in-time recovery and regular restore drills. Proposed targets: **RPO 15 minutes,
RTO 4 hours** (to be confirmed).

## 5.9 Threats specifically addressed

| Threat | Mitigation |
|---|---|
| Cross-tenant data leak | RLS, org-scoped jobs/cache/storage, non-superuser DB role |
| Inferring one venue from an aggregate | k >= 5, distinct orgs, no-dominance, nested-cohort suppression, competitor-set snapshot and edit lock |
| Peer-pool poisoning by fake venues | Thresholds count only verified venues; unverified uploads join only qualifying cohorts, capped at half (decided 2026-09-29, 07 §7.1) |
| Credential theft from POS connections | Envelope encryption, decrypt only in jobs, secrets manager, rotation |
| Raw data or PII reaching an AI provider | Whitelisted findings-only input schema |
| Malicious uploads | Type/size limits, malware scan, sandboxed parsing, formula-injection escaping |
