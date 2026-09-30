#!/usr/bin/env bash
# infra/scripts/deploy.sh
# Build, migrate, roll out. Used by .github/workflows/deploy-hub.yml; also runs from any machine
# with Docker, Node 22 and AWS credentials for the account.
#
#   HUB_ALERT_EMAIL=you@example.com ./scripts/deploy.sh [image-tag]
#
# 1. Build api/ and push it to ECR as <tag> (default: the current commit).
# 2. Deploy with only the migrate task on the new image (running services unchanged) and run it.
#    A failed migration stops here: the app keeps running the old image.
# 3. Deploy the app services on the new image (rolling; the load balancer drains old tasks).
set -euo pipefail

REGION=ap-southeast-2
STACK=SuccessMeterHub
here=$(cd "$(dirname "$0")/.." && pwd)
root=$(cd "$here/.." && pwd)
tag=${1:-$(git -C "$root" rev-parse --short=12 HEAD)}
: "${HUB_ALERT_EMAIL:?Set HUB_ALERT_EMAIL (budget alerts)}"

output() {
  aws cloudformation describe-stacks --region "$REGION" --stack-name "$STACK" \
    --query "Stacks[0].Outputs[?OutputKey=='$1'].OutputValue" --output text
}
cdk_deploy() {
  (cd "$here" && npx cdk deploy "$STACK" --require-approval never --ci -c alertEmail="$HUB_ALERT_EMAIL" "$@")
}

repo_uri=$(output RepositoryUri)
cluster=$(output ClusterName)

echo "== 1/3 image $repo_uri:$tag"
if aws ecr describe-images --region "$REGION" --repository-name "${repo_uri##*/}" --image-ids imageTag="$tag" >/dev/null 2>&1; then
  echo "already pushed (a redeploy or rollback needs no Docker)"
else
  aws ecr get-login-password --region "$REGION" | docker login --username AWS --password-stdin "${repo_uri%%/*}"
  docker build --platform linux/amd64 -t "$repo_uri:$tag" "$root/api"
  docker push "$repo_uri:$tag"
fi

# The image the app runs now (unset on the first deploy): kept while migrations run.
current=$(aws ecs describe-services --region "$REGION" --cluster "$cluster" --services "$(aws ecs list-services --region "$REGION" --cluster "$cluster" --query "serviceArns[?contains(@, 'Web')]|[0]" --output text)" \
  --query 'services[0].taskDefinition' --output text 2>/dev/null || true)
app_tag=""
if [[ -n "$current" && "$current" != "None" ]]; then
  image=$(aws ecs describe-task-definition --region "$REGION" --task-definition "$current" --query 'taskDefinition.containerDefinitions[0].image' --output text)
  [[ "$image" == *:not-yet-pushed ]] || app_tag=${image##*:}
fi

echo "== 2/3 migrations on $tag (app stays on ${app_tag:-nothing yet})"
if [[ -n "$app_tag" ]]; then cdk_deploy -c appImageTag="$app_tag" -c migrateImageTag="$tag"; else cdk_deploy -c migrateImageTag="$tag"; fi
task=$(aws ecs run-task --region "$REGION" --cluster "$cluster" --launch-type FARGATE \
  --task-definition success-meter-hub-migrate \
  --network-configuration "awsvpcConfiguration={subnets=[$(output TaskSubnets)],securityGroups=[$(output AppSecurityGroup)],assignPublicIp=ENABLED}" \
  --query 'tasks[0].taskArn' --output text)
echo "migrate task $task"
aws ecs wait tasks-stopped --region "$REGION" --cluster "$cluster" --tasks "$task"
code=$(aws ecs describe-tasks --region "$REGION" --cluster "$cluster" --tasks "$task" --query 'tasks[0].containers[0].exitCode' --output text)
aws logs get-log-events --region "$REGION" --log-group-name "$(output LogGroup)" \
  --log-stream-name "migrate/app/${task##*/}" --query 'events[].message' --output text 2>/dev/null | tr '\t' '\n' | tail -40 || true
if [[ "$code" != "0" ]]; then
  echo "Migrations failed (exit $code). The app is unchanged." >&2
  exit 1
fi

echo "== 3/3 app on $tag"
cdk_deploy -c appImageTag="$tag" -c migrateImageTag="$tag"
echo "Deployed $tag."
