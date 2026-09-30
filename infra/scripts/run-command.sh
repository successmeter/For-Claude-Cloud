#!/usr/bin/env bash
# infra/scripts/run-command.sh
# Runs one artisan command in the cloud as a one-off task on the current app image, and prints
# its output. Example (Plan D): ./scripts/run-command.sh hub:invite-business "Oxford St Cafe" owner@example.com
set -euo pipefail

REGION=ap-southeast-2
STACK=SuccessMeterHub
output() {
  aws cloudformation describe-stacks --region "$REGION" --stack-name "$STACK" \
    --query "Stacks[0].Outputs[?OutputKey=='$1'].OutputValue" --output text
}
[[ $# -gt 0 ]] || { echo "Usage: $0 <artisan command> [args...]" >&2; exit 1; }

cluster=$(output ClusterName)
overrides=$(jq -cn --args '{containerOverrides: [{name: "app", command: (["php", "artisan"] + $ARGS.positional)}]}' "$@")
task=$(aws ecs run-task --region "$REGION" --cluster "$cluster" --launch-type FARGATE \
  --task-definition success-meter-hub-worker --overrides "$overrides" \
  --network-configuration "awsvpcConfiguration={subnets=[$(output TaskSubnets)],securityGroups=[$(output AppSecurityGroup)],assignPublicIp=ENABLED}" \
  --query 'tasks[0].taskArn' --output text)
aws ecs wait tasks-stopped --region "$REGION" --cluster "$cluster" --tasks "$task"
aws logs get-log-events --region "$REGION" --log-group-name "$(output LogGroup)" \
  --log-stream-name "worker/app/${task##*/}" --query 'events[].message' --output text | tr '\t' '\n'
exit "$(aws ecs describe-tasks --region "$REGION" --cluster "$cluster" --tasks "$task" --query 'tasks[0].containers[0].exitCode' --output text)"
