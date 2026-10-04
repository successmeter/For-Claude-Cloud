# Hosting the trial on Laravel Cloud (Sydney)

For the demo and trial phase the Hub runs on **Laravel Cloud** in Sydney, and the AWS stack (`infra/`,
`docs/hosting-aws.md`) waits for launch, so AWS credits are kept for then. Nothing in the code is specific to either:
the same app runs on both, configured by environment variables.

| Piece | Trial (this guide) | Launch (AWS guide) |
|---|---|---|
| Hub API, queue worker, scheduler | Laravel Cloud app (root directory `api`) | ECS Fargate |
| Database | Laravel Cloud Serverless Postgres (hibernates when idle) | RDS PostgreSQL 16 |
| Per-org encryption keys | **AWS KMS** (one key, about US$1 a month) | AWS KMS |
| Upload snapshots | Laravel Cloud object storage (app-encrypted) | S3 + KMS |
| Upload virus scan | **Off**, and every upload is logged as not scanned | ClamAV |
| Email | Resend or Postmark over SMTP | SES |
| Web Performance API (Node) | Render (Docker) + a small Postgres | ECS Fargate |
| Revenue app and Web app | Netlify (unchanged) | Netlify |

Cost: billed by use; see Laravel Cloud's pricing page. The database is the line to watch: Serverless Postgres is
billed per second while awake (US$0.106/hour at the time of writing), so **turn hibernation on** for it.

## Before you start

- A Laravel Cloud account with GitHub access to `successmeter/For-Claude-Cloud`.
- An AWS account (for the KMS key only).
- An email provider with SMTP: Resend (`smtp.resend.com`) or Postmark, with `successmeter.tech` verified.
- DNS for `successmeter.tech`.

## 1. The encryption key (AWS, once)

Square tokens and upload snapshots are encrypted with a key per business, and those keys are wrapped by AWS KMS. A
key on the app's own disk would be lost on every deploy, so the trial uses KMS too.

1. AWS console, region **Asia Pacific (Sydney)** -> **KMS** -> Create key: symmetric, encrypt and decrypt, alias
   `success-meter-hub`. Note its **ARN**.
2. **IAM** -> Users -> Create user `success-meter-hub-kms` (no console access). Add an inline policy (put your key's
   ARN in):
   ```json
   {
     "Version": "2012-10-17",
     "Statement": [{
       "Effect": "Allow",
       "Action": ["kms:GenerateDataKey", "kms:Decrypt"],
       "Resource": "arn:aws:kms:ap-southeast-2:ACCOUNT:key/KEY-ID"
     }]
   }
   ```
3. That user -> Security credentials -> Create access key ("Application running outside AWS"). Keep the two values
   for step 4; nowhere else.

Keep this key for launch: data encrypted during the trial can only be read with it.

## 2. Create the app on Laravel Cloud

1. **New application** -> repository `successmeter/For-Claude-Cloud`, branch `main`, region **Sydney**. Laravel
   Cloud detects the monorepo: choose the root directory **`api`**.
2. **Database**: add a **Serverless Postgres** database (Sydney) to the environment and attach it. Turn
   **hibernation** on. Laravel Cloud gives the app its owner credentials (`DB_*`); the app does not run as that owner
   (see step 4).
3. **Object storage**: add a private bucket and attach it to the app (it sets `AWS_BUCKET`, `AWS_ENDPOINT` and keys).
4. **Compute**: the smallest size is enough for the trial. Keep the app awake (no app hibernation): the queue worker
   and the nightly Square sync need it.
5. **Background process**: `php artisan queue:work --sleep=1 --tries=3 --max-time=3600`.
6. **Scheduler**: on.
7. **Deploy command**: replace the default `php artisan migrate --force` with
   ```
   php artisan hub:release
   ```
   It migrates as the owner, then creates or updates the restricted `app_user` role the app runs as. Never use the
   bare `migrate`.

## 3. Passport keys (once)

The Hub signs sign-in tokens for the other tools. On your computer or AWS CloudShell:

```bash
openssl genrsa -out private.key 4096 && openssl rsa -in private.key -pubout -out public.key
```

Paste each file's whole content into the variables below (in double quotes, line breaks kept), then delete both files.

## 4. Environment variables

In the environment's settings (custom domains in step 5; until then use the `*.laravel.cloud` address in the URLs):

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hub.successmeter.tech
APP_FRONTEND_URL=https://app.successmeter.tech
SANCTUM_STATEFUL_DOMAINS=app.successmeter.tech
HUB_ISSUER=https://hub.successmeter.tech
OPENID_FORCE_HTTPS=true
TRUSTED_PROXIES=*

# Run as app_user, never as the owner Laravel Cloud injects (the app refuses to start otherwise).
HUB_DB_CONNECTION=pgsql_app
DB_APP_USERNAME=app_user
DB_APP_PASSWORD=<a long random password: openssl rand -base64 32>
DB_SSLMODE=require

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

KMS_DRIVER=aws
KMS_KEY_ID=<the key ARN from step 1>
KMS_REGION=ap-southeast-2
KMS_AWS_ACCESS_KEY_ID=<from step 1>
KMS_AWS_SECRET_ACCESS_KEY=<from step 1>

SNAPSHOTS_DRIVER=s3
SNAPSHOTS_SSE=none

# Laravel Cloud can't run ClamAV. Uploads are only parsed as CSV; each is logged as not scanned.
INGEST_SCANNER=off

MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=587
MAIL_USERNAME=resend
MAIL_PASSWORD=<Resend API key>
MAIL_FROM_ADDRESS=no-reply@successmeter.tech
MAIL_FROM_NAME="Success Meter"

PASSPORT_PRIVATE_KEY="<private.key>"
PASSPORT_PUBLIC_KEY="<public.key>"
```

`APP_KEY` is set by Laravel Cloud. Square (when ready, see the end) adds `SQUARE_APPLICATION_ID`,
`SQUARE_APPLICATION_SECRET` and `SQUARE_ENVIRONMENT`.

Deploy. The deploy log ends with `app_user password applied.`

## 5. Domain

Laravel Cloud -> Domains: add `hub.successmeter.tech` and create the DNS record it shows. Then check:

- `https://hub.successmeter.tech/up` answers 200;
- `https://hub.successmeter.tech/.well-known/openid-configuration` shows the sign-in settings.

## 6. The Revenue app (Netlify)

In the Revenue app's Netlify site: `VITE_API_ORIGIN=https://hub.successmeter.tech`, then deploy. Invite the first
business from Laravel Cloud's command runner:

```
php artisan hub:invite-business "Oxford St Cafe" owner@example.com
```

## 7. The Web Performance tool (Render)

Laravel Cloud runs PHP only, so the Web tool's Node API (`successmeter/traffic-dashboard`) goes on Render:

1. A Postgres database (Render's, or Neon's free tier); note its connection URL.
2. **New Web Service** -> the repository, **Docker** runtime, region Singapore (the closest), start command default
   (`web`). **Pre-deploy command**: `/app/docker/entrypoint.sh migrate`.
3. Register it with the Hub from Laravel Cloud's command runner; each prints its credentials **once**:
   ```
   php artisan hub:client web https://web.successmeter.tech/auth/callback https://web.successmeter.tech/
   php artisan hub:webhook-endpoint web https://web-api.successmeter.tech/hooks/hub
   ```
4. Environment: `NODE_ENV=production`, `DATABASE_URL` (with `?sslmode=require`), `SESSION_SECRET`
   (`openssl rand -base64 48`), `GOOGLE_SERVICE_ACCOUNT_JSON`, `HUB_ISSUER=https://hub.successmeter.tech`,
   `HUB_CLIENT_ID`, `HUB_CLIENT_SECRET`, `HUB_WEBHOOK_SECRET`, `HUB_REDIRECT_URI=https://web.successmeter.tech/auth/callback`,
   `HUB_POST_LOGOUT_REDIRECT_URI=https://web.successmeter.tech/`, `FRONTEND_ORIGIN=https://web.successmeter.tech`.
5. Custom domain `web-api.successmeter.tech`; then in the Web app's Netlify site `WEB_API_ORIGIN` = that address.

## Square

As in `docs/hosting-aws.md` ("Square"), except the secret goes straight into the environment variables: Square's
OAuth redirect URL is `https://hub.successmeter.tech/api/pos/square/callback`.

## Limits of the trial setup, and moving to AWS

- **No virus scan** of uploads (logged as `ingest.upload_not_scanned`). Back on at launch (ClamAV on AWS).
- **KMS by access key** rather than an AWS role. Rotate the key in IAM if it is ever exposed.
- **Backups**: whatever the Laravel Cloud database plan includes; check its restore options before real venues join.
- **Moving to AWS:** deploy the AWS stack, give its task role use of the same KMS key, copy the database
  (`pg_dump` / `pg_restore`, then `hub:release`) and the snapshot files, switch DNS. Plan this as its own task.
