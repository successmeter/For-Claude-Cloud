#!/usr/bin/env bash
# infra/scripts/deploy.sh
# Build, migrate, roll out. Used by .github/workflows/deploy-hub.yml; also runs from any machine
# with Docker, Node 22 and AWS credentials for the account.
#
#   HUB_ALERT_EMAIL=you@example.com [WEB_IMAGE_TAG=<tag>] ./scripts/deploy.sh [hub-image-tag]
#
# 1. Build api/ and push it to ECR as <tag> (default: the current commit).
# 2. Deploy with only the migrate tasks on the new images (running services unchanged) and run
#    them: the Hub's, and the Web API's when WEB_IMAGE_TAG names a new Web image (published by the
#    Web repository's "Publish Web image" workflow). A failed migration stops here: the services
#    keep running the images they had.
# 3. Deploy the services on the new images (rolling; the load balancer drains old tasks).
# Without WEB_IMAGE_TAG the Web API keeps the image it runs (or stays off if it has none yet).
set -euo pipefail

REGION=ap-southeast-2
STACK=SuccessMeterHub
here=$(cd "$(dirname "$0")/.." && pwd)
root=$(cd "$here/.." && pwd)
tag=${1:-$(git -C "$root" rev-parse --short=12 HEAD)}
web_new=${WEB_IMAGE_TAG:-}
: "${HUB_ALERT_EMAIL:?Set HUB_ALERT_EMAIL (budget alerts)}"

output() {
  aws cloudformation describe-stacks --region "$REGION" --stack-name "$STACK" \
    --query "Stacks[0].Outputs[?OutputKey=='$1'].OutputValue" --output text
}
# cdk_deploy <hub app tag> <hub migrate tag> <web tag> <web migrate tag>; empty tags are left out.
cdk_deploy() {
  local args=()
  [[ -n "$1" ]] && args+=(-c "appImageTag=$1")
  [[ -n "$2" ]] && args+=(-c "migrateImageTag=$2")
  [[ -n "$3" ]] && args+=(-c "webImageTag=$3")
  [[ -n "$4" ]] && args+=(-c "webMigrateImageTag=$4")
  (cd "$here" && npx cdk deploy "$STACK" --require-approval never --ci -c alertEmail="$HUB_ALERT_EMAIL" "${args[@]}")
}
# The image tag a service runs now ("" when it runs nothing yet).
running_tag() {
  local def image
  def=$(aws ecs describe-services --region "$REGION" --cluster "$cluster" --services "$1" \
    --query 'services[0].taskDefinition' --output text 2>/dev/null || true)
  [[ -z "$def" || "$def" == "None" ]] && return 0
  image=$(aws ecs describe-task-definition --region "$REGION" --task-definition "$def" --query 'taskDefinition.containerDefinitions[0].image' --output text)
  [[ "$image" == *:not-yet-pushed ]] || echo "${image##*:}"
}
# run_migration <task family> <security group> <log stream prefix>
run_migration() {
  local task code
  task=$(aws ecs run-task --region "$REGION" --cluster "$cluster" --launch-type FARGATE \
    --task-definition "$1" \
    --network-configuration "awsvpcConfiguration={subnets=[$(output TaskSubnets)],securityGroups=[$2],assignPublicIp=ENABLED}" \
    --query 'tasks[0].taskArn' --output text)
  echo "$1 task $task"
  aws ecs wait tasks-stopped --region "$REGION" --cluster "$cluster" --tasks "$task"
  code=$(aws ecs describe-tasks --region "$REGION" --cluster "$cluster" --tasks "$task" --query 'tasks[0].containers[0].exitCode' --output text)
  aws logs get-log-events --region "$REGION" --log-group-name "$(output LogGroup)" \
    --log-stream-name "$3/app/${task##*/}" --query 'events[].message' --output text 2>/dev/null | tr '\t' '\n' | tail -40 || true
  if [[ "$code" != "0" ]]; then
    echo "$1 failed (exit $code). The services are unchanged." >&2
    exit 1
  fi
}

repo_uri=$(output RepositoryUri)
web_repo_uri=$(output WebRepositoryUri)
cluster=$(output ClusterName)

echo "== 1/3 images"
if aws ecr describe-images --region "$REGION" --repository-name "${repo_uri##*/}" --image-ids imageTag="$tag" >/dev/null 2>&1; then
  echo "Hub $tag already pushed (a redeploy or rollback needs no Docker)"
else
  aws ecr get-login-password --region "$REGION" | docker login --username AWS --password-stdin "${repo_uri%%/*}"
  docker build --platform linux/amd64 -t "$repo_uri:$tag" "$root/api"
  docker push "$repo_uri:$tag"
fi
if [[ -n "$web_new" ]] && ! aws ecr describe-images --region "$REGION" --repository-name "${web_repo_uri##*/}" --image-ids imageTag="$web_new" >/dev/null 2>&1; then
  echo "Web image $web_new is not in $web_repo_uri. Publish it first (Web repo: Actions -> Publish Web image)." >&2
  exit 1
fi

hub_now=$(running_tag success-meter-hub-web)
web_now=$(running_tag success-meter-web-api)
web_target=${web_new:-$web_now}

echo "== 2/3 migrations (Hub $tag; Web ${web_new:-unchanged}); services stay on Hub ${hub_now:-nothing yet}, Web ${web_now:-nothing yet}"
cdk_deploy "$hub_now" "$tag" "$web_now" "$web_target"
run_migration success-meter-hub-migrate "$(output AppSecurityGroup)" migrate
if [[ -n "$web_new" ]]; then
  run_migration success-meter-web-migrate "$(output WebSecurityGroup)" web-migrate
fi

echo "== 3/3 services: Hub $tag, Web ${web_target:-not deployed}"
cdk_deploy "$tag" "$tag" "$web_target" "$web_target"
echo "Deployed Hub $tag${web_target:+, Web $web_target}."
