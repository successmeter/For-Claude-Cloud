# Hosting the Hub on AWS (Sydney)

This puts the Hub (the Revenue tool's server in `api/`) online at `https://hub.successmeter.tech`, paid from the AWS
Activate credits. Everything runs in **Sydney (ap-southeast-2)**. You click through the AWS console and GitHub; the
commands are copy-and-paste in **AWS CloudShell** (a terminal in your browser, nothing to install).

**Time:** about an hour of your time, spread over a day (certificate and email checks wait on DNS).

## What gets created

| Part | Service | Notes |
|---|---|---|
| Web server, queue worker, scheduler | ECS Fargate (containers) | One image, built from `api/Dockerfile` |
| Web Performance API (the Web tool's server) | ECS Fargate, same cluster | Image from `traffic-dashboard`'s `Dockerfile`; its own database (`web`) on the same Postgres server; `https://web-api.successmeter.tech` on the same load balancer |
| Virus scanning of uploads | ClamAV on ECS Fargate | Reachable only from the app |
| Database | RDS PostgreSQL 16 | Private, encrypted, daily backups kept 7 days, deletion protection on |
| Upload snapshots | S3 | Private, encrypted, deleted after 90 days |
| Encryption keys | KMS | Per-business data keys, database, files, secrets |
| Passwords and signing keys | Secrets Manager | Never in code or logs |
| HTTPS | Load balancer + free certificate (ACM) | HTTP redirects to HTTPS |
| Invitation email | SES | From `no-reply@successmeter.tech` |
| Spend alert | AWS Budgets | Emails you at 80% of US$250/month, **before credits** |
| Deploys | GitHub Actions | Only from `main`, started by you |

The whole setup is code in `infra/` (AWS CDK). Its tests check the security properties above.

**Rough cost:** about **US$145-195 a month** before credits (containers ~US$65 with the Web API, load balancer ~US$25, database
~US$20, public IP addresses ~US$20, logs, keys and secrets ~US$10-20). The US$10,000 credits cover that for the 24
months they last. These are estimates; the budget alert tells you the real figure.

## Before you start

- [ ] AWS account created with a company email, **root MFA on**, and an admin user in **IAM Identity Center** for
      daily use. Sign in as that admin, not root.
- [ ] Region (top right of the console) set to **Asia Pacific (Sydney)**.
- [ ] Activate credits applied (Billing -> Credits shows them).
- [ ] Access to the DNS settings for `successmeter.tech` (wherever the domain's DNS is managed).
- [ ] Admin access to the `successmeter/For-Claude-Cloud` repository on GitHub.

The names used below come from `infra/cdk.json` (`hub.successmeter.tech`, `https://app.successmeter.tech`,
`no-reply@successmeter.tech`, budget US$250). Change them there first if you want different ones.

## 1. Open CloudShell and get the code

In the AWS console (Sydney), click the **CloudShell** icon (a `>_` in the top bar). In the terminal:

```bash
git clone https://github.com/successmeter/For-Claude-Cloud.git
cd For-Claude-Cloud/infra
npm ci
```

(If the repository is private by then, clone with a GitHub personal access token:
`git clone https://<token>@github.com/successmeter/For-Claude-Cloud.git`.)

## 2. Prepare the account for CDK (once)

```bash
npx cdk bootstrap aws://$(aws sts get-caller-identity --query Account --output text)/ap-southeast-2
```

## 3. Create the infrastructure

Replace the email with where budget alerts should go:

```bash
npx cdk deploy -c alertEmail=you@successmeter.tech
```

Type `y` when it lists security changes. It takes about 15-25 minutes. **While it runs, the HTTPS certificate waits
for you:**

1. Open **Certificate Manager** (Sydney) -> the certificate for `hub.successmeter.tech` (it also covers
   `web-api.successmeter.tech`) -> copy each **CNAME name** and **CNAME value** (one per name).
2. At your DNS provider, add a **CNAME** record for each.
3. Within a few minutes the certificate shows **Issued** and the deploy continues.

At the end it prints **Outputs**. Keep them (or find them later in **CloudFormation -> SuccessMeterHub -> Outputs**).

Nothing is running yet: the servers start after the first image is deployed (step 6).

## 4. Create the app's secrets (once)

```bash
./scripts/init-secrets.sh
```

This generates the app key and the sign-in signing keys and stores them in Secrets Manager. They are never shown.

## 5. Email

1. At your DNS provider, add the three **CNAME** records from the outputs `MailDkim1`, `MailDkim2` and `MailDkim3`
   (each output is `name CNAME value`). SES shows the domain as **Verified** once they are live.
2. New SES accounts can only send to addresses you verify. In **SES -> Account dashboard**, click **Request production
   access**: say it sends invitation emails to business owners and staff who were invited by name, about 100 a month.
   Approval usually takes a day.

## 6. First deploy from GitHub

1. In GitHub, open the repository -> **Settings -> Secrets and variables -> Actions -> Variables** and add:
   - `AWS_DEPLOY_ROLE_ARN`: the `DeployRoleArn` output
   - `HUB_ALERT_EMAIL`: the same alert email as step 3
2. Open **Actions -> Deploy Hub -> Run workflow** (branch `main`).

It builds the image, runs the database migrations, then starts the web server, worker, scheduler and virus scanner
(about 15 minutes; ClamAV downloads its virus signatures on first start). If migrations fail, it stops and the app
is left as it was.

## 7. Point the domain at it

At your DNS provider, add a **CNAME** record: `hub` -> the `LoadBalancerDns` output (and, for the Web tool, `web-api` ->
the same address, output `WebApiDns`; it looks like
`Succe-Alb...ap-southeast-2.elb.amazonaws.com`).

## 8. Check

Open these in a browser:

- `https://hub.successmeter.tech/up` shows a green "Application up" page
- `https://hub.successmeter.tech/.well-known/openid-configuration` shows the sign-in settings (JSON)

## Everyday use

| Task | How |
|---|---|
| **Deploy** the latest `main` | GitHub -> Actions -> Deploy Hub -> Run workflow |
| **Roll back** to an earlier version | CloudShell: `cd For-Claude-Cloud/infra && git pull && HUB_ALERT_EMAIL=you@... ./scripts/deploy.sh <12-character commit id>` (reuses the image already in AWS) |
| **Invite a business** (creates it and emails the owner a link; the link is also printed) | CloudShell: `./scripts/run-command.sh hub:invite-business "Oxford St Cafe" owner@example.com` |
| **Logs** | CloudWatch -> Log groups -> the group named in the `LogGroup` output (`web/`, `worker/`, `scheduler/`, `clamd/`, `migrate/`) |
| **Database backups** | RDS -> Databases -> the database -> Maintenance & backups (daily, 7 days). Deleting the stack keeps a final snapshot. |
| **Rotate the database app password** | Secrets Manager -> `success-meter-hub/app` -> change `DB_APP_PASSWORD` -> run Deploy Hub (the migration step applies it) |
| **Spend** | Billing -> Budgets (alerts at 80% and when the month is forecast over budget, counted before credits) |

## The Web Performance tool

Its server runs in this setup too (`traffic-dashboard`, the `WebApi` service); its browser app stays on Netlify at
`https://web.successmeter.tech` and forwards `/api` and `/auth` to `https://web-api.successmeter.tech`. The names are
`webApiDomain` and `webFrontendUrl` in `infra/cdk.json`. Do this once the Hub is running (steps 1-8), in CloudShell
from `For-Claude-Cloud/infra`:

1. **Secrets.** Download the GA4 service account's key (Google Cloud -> IAM -> Service accounts -> Keys -> JSON),
   upload it to CloudShell (Actions -> Upload file), then run `./scripts/init-web-secrets.sh ga4-key.json` and delete
   the file (`rm ga4-key.json`). This stores a session secret and the key; nothing is printed.
2. **Register the Web tool with the Hub.** The credentials go straight into the Web secret and are never shown:
   ```bash
   ./scripts/run-command.sh hub:client web https://web.successmeter.tech/auth/callback https://web.successmeter.tech/ --aws-secret=success-meter-web/app
   ./scripts/run-command.sh hub:webhook-endpoint web https://web-api.successmeter.tech/hooks/hub --aws-secret=success-meter-web/app
   ```
3. **Let the Web repository publish images.** In GitHub, `successmeter/traffic-dashboard` -> Settings -> Secrets and
   variables -> Actions -> Variables: add `AWS_WEB_PUBLISH_ROLE_ARN` = the `WebPublishRoleArn` output.
4. **Publish and deploy.** In `traffic-dashboard`: Actions -> **Publish Web image** -> Run workflow (branch `main`); it
   prints a tag. Then here: Actions -> **Deploy Hub** -> Run workflow with **web_image_tag** = that tag. It creates the
   `web` database on first run, applies the Web migrations, then starts the Web API.
5. **DNS.** `web-api` CNAME -> the `WebApiDns` output. Check `https://web-api.successmeter.tech/health` shows `{"ok":true}`.
6. **Browser app (Netlify, `traffic-dashboard` site).** Set the environment variable `WEB_API_ORIGIN` =
   `https://web-api.successmeter.tech`, point `web.successmeter.tech` at the site, then unlock auto-publishing and
   deploy `main` once the Web changes are merged. Without `WEB_API_ORIGIN` its production build fails on purpose.

Later deploys: publish a new Web image, then Deploy Hub with its tag. Deploy Hub without a tag leaves the Web API as it
is. Logs: the `web-api/` and `web-migrate/` streams in the `LogGroup`. GA4 key change:
`./scripts/init-web-secrets.sh --ga4 new-key.json`, then Deploy Hub (any tag) so the service restarts with it.

## Not covered here yet

- **The Revenue app** (Plan D) stays on Netlify and forwards `/api/*` and `/sanctum/*` to
  `https://hub.successmeter.tech`. Its address is `frontendUrl` in `infra/cdk.json` (`https://app.successmeter.tech`):
  invitation links point there and only that host gets a sign-in session, so change it there (and redeploy) if the app
  lives elsewhere.
- **Before public launch:** Multi-AZ database, load balancer access logs, a restore drill (05 §5.8).
- **GA4 ingestion on a schedule** (the Web tool's README): dashboards read cached reports; a scheduled worker is a
  follow-up.
