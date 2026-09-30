// infra/bin/hub.ts
// Deploy: see docs/hosting-aws.md. Settings come from CDK context (-c key=value or cdk.json).
import * as cdk from 'aws-cdk-lib';
import { AwsSolutionsChecks } from 'cdk-nag';
import { HubStack } from '../lib/hub-stack.ts';

const app = new cdk.App();
const setting = (name: string): string => {
  const value = app.node.tryGetContext(name);
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error(`Set -c ${name}=... (see docs/hosting-aws.md)`);
  }
  return value.trim();
};
const optional = (name: string): string | undefined => {
  const value = app.node.tryGetContext(name);
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : undefined;
};

new HubStack(app, 'SuccessMeterHub', {
  env: { account: process.env.CDK_DEFAULT_ACCOUNT, region: 'ap-southeast-2' },
  description: 'Success Meter Hub API (Sydney)',
  domainName: setting('domainName'),
  alertEmail: setting('alertEmail'),
  frontendUrl: setting('frontendUrl'),
  mailFrom: setting('mailFrom'),
  githubRepo: setting('githubRepo'),
  monthlyBudgetUsd: Number(optional('monthlyBudgetUsd') ?? '250'),
  appImageTag: optional('appImageTag'),
  migrateImageTag: optional('migrateImageTag'),
  webApiDomain: setting('webApiDomain'),
  webFrontendUrl: setting('webFrontendUrl'),
  webGithubRepo: setting('webGithubRepo'),
  webImageTag: optional('webImageTag'),
  webMigrateImageTag: optional('webMigrateImageTag'),
});

cdk.Validations.of(app).addPlugins(new AwsSolutionsChecks(app, { verbose: true }));
