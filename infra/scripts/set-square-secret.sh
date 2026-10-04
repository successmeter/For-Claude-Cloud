#!/usr/bin/env bash
# infra/scripts/set-square-secret.sh
# Stores the Square application secret in the Hub's app secret (Plan E). Paste it when asked: it is
# read without echo, never printed and never left on disk. Then deploy with
#   -c squareApplicationId=<application id> -c squareEnvironment=sandbox|production
# (see docs/hosting-aws.md, "Square"). Run in AWS CloudShell (Sydney) or anywhere with the AWS CLI and jq.
set -euo pipefail

REGION=ap-southeast-2
SECRET_ID=success-meter-hub/app

for tool in aws jq; do
  command -v "$tool" >/dev/null || { echo "Needs $tool" >&2; exit 1; }
done

read -r -s -p "Square application secret: " square_secret
echo
[[ -n "$square_secret" ]] || { echo "Nothing entered." >&2; exit 1; }

current=$(aws secretsmanager get-secret-value --region "$REGION" --secret-id "$SECRET_ID" --query SecretString --output text)
updated=$(jq --arg s "$square_secret" '. + {SQUARE_APPLICATION_SECRET: $s}' <<<"$current")
aws secretsmanager put-secret-value --region "$REGION" --secret-id "$SECRET_ID" --secret-string "$updated" >/dev/null
unset square_secret updated current
echo "Stored SQUARE_APPLICATION_SECRET in $SECRET_ID. Redeploy (or restart the services) to pick it up."
