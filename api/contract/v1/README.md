# Hub contract v1

The interface between the Hub (this app) and the other tools. The JSON Schemas in this folder are
the source of truth for every `/hub/v1` payload and every webhook body. Design:
`plans/2026-09-29-plan-b-hub-contract-design.md`.

| Schema | Used by |
|---|---|
| `me` | `GET /hub/v1/me` |
| `org-list` | `GET /hub/v1/me/orgs` |
| `org` | `GET /hub/v1/orgs/{org}` |
| `tool-link` | `PUT /hub/v1/orgs/{org}/tools/{tool}` |
| `competitor-set-list` | `GET /hub/v1/orgs/{org}/competitor-sets` |
| `competitor-set` | `GET` / `POST` / `PATCH` of one set, and member writes |
| `activation` | `POST /hub/v1/orgs/{org}/competitor-sets/{set}/activation` |
| `webhook-event` | body of every webhook |
| `problem` | every error response (RFC 9457 `application/problem+json`) |

## Rules

- **Every object is closed** (`additionalProperties: false`), except `problem`, which RFC 9457 lets
  carry extension members. Adding a field is therefore a schema change: update the schema here,
  then the Web tool's vendored copy (`traffic-dashboard/contract/v1`, with the Hub commit it came
  from in `contract/CONTRACT_SOURCE`). A change that would break an existing consumer needs `/hub/v2`.
- **Access tokens are opaque.** Their JWT `sub` is an internal id. The user's id outside the Hub is
  `sub` from the id_token, `/oauth/userinfo` or `/hub/v1/me` (a UUID).
- **Tenant selection:** every org-scoped call sends `X-Hub-Org: <org id>`. An org the caller cannot
  use is a 404 `org_not_found`, never a 403, so responses do not reveal which orgs exist.
- **Webhooks carry ids only.** Refetch through the API. Delivery is at-least-once: dedupe on
  `Hub-Event-Id`, reject a `Hub-Timestamp` more than 5 minutes off, and verify
  `Hub-Signature: v1=<hex HMAC-SHA256(secret, timestamp + "." + raw body)>` (during secret rotation
  the header carries two comma-separated `v1=` values; accept either).
