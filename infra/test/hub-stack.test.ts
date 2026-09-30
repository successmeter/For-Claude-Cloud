// infra/test/hub-stack.test.ts
// The properties the Hub relies on, checked on the synthesized template (no AWS account needed).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as cdk from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import { AwsSolutionsChecks } from 'cdk-nag';
import { HubStack, type HubStackProps } from '../lib/hub-stack.ts';

const props: HubStackProps = {
  env: { account: '111122223333', region: 'ap-southeast-2' },
  domainName: 'hub.example.com',
  alertEmail: 'alerts@example.com',
  frontendUrl: 'https://app.example.com',
  mailFrom: 'no-reply@example.com',
  githubRepo: 'successmeter/For-Claude-Cloud',
  monthlyBudgetUsd: 250,
};

const synth = (extra: Partial<HubStackProps> = {}) => {
  const app = new cdk.App();
  const stack = new HubStack(app, 'Hub', { ...props, ...extra });
  return { app, stack, template: Template.fromStack(stack) };
};

const containers = (template: Template, family: string) => {
  const defs = template.findResources('AWS::ECS::TaskDefinition', { Properties: { Family: family } });
  const [def] = Object.values(defs);
  return def.Properties.ContainerDefinitions[0];
};

test('the database is Postgres 16, private, encrypted, backed up and protected', () => {
  const { template } = synth();
  template.hasResourceProperties('AWS::RDS::DBInstance', {
    Engine: 'postgres',
    EngineVersion: Match.stringLikeRegexp('^16'),
    PubliclyAccessible: false,
    StorageEncrypted: true,
    BackupRetentionPeriod: 7,
    DeletionProtection: true,
  });
  template.hasResourceProperties('AWS::RDS::DBParameterGroup', { Parameters: { 'rds.force_ssl': '1' } });
});

test('snapshots are private, KMS-encrypted and TLS-only', () => {
  const { template } = synth();
  template.hasResourceProperties('AWS::S3::Bucket', {
    PublicAccessBlockConfiguration: { BlockPublicAcls: true, BlockPublicPolicy: true, IgnorePublicAcls: true, RestrictPublicBuckets: true },
    BucketEncryption: { ServerSideEncryptionConfiguration: [Match.objectLike({ ServerSideEncryptionByDefault: { SSEAlgorithm: 'aws:kms', KMSMasterKeyID: Match.anyValue() } })] },
  });
  template.hasResourceProperties('AWS::S3::BucketPolicy', {
    PolicyDocument: { Statement: Match.arrayWith([Match.objectLike({ Effect: 'Deny', Condition: { Bool: { 'aws:SecureTransport': 'false' } } })]) },
  });
});

test('HTTPS only: port 80 redirects, 443 uses the certificate and a modern policy', () => {
  const { template } = synth();
  template.hasResourceProperties('AWS::ElasticLoadBalancingV2::Listener', {
    Port: 80, DefaultActions: [Match.objectLike({ Type: 'redirect', RedirectConfig: Match.objectLike({ Protocol: 'HTTPS', StatusCode: 'HTTP_301' }) })],
  });
  template.hasResourceProperties('AWS::ElasticLoadBalancingV2::Listener', { Port: 443, Protocol: 'HTTPS', Certificates: [Match.anyValue()] });
  template.hasResourceProperties('AWS::CertificateManager::Certificate', { DomainName: 'hub.example.com', ValidationMethod: 'DNS' });
  template.hasResourceProperties('AWS::ElasticLoadBalancingV2::TargetGroup', { HealthCheckPath: '/up', Port: 8080 });
});

test('only the migrate task holds the database owner password', () => {
  const { template } = synth({ appImageTag: 'abc123' });
  const names = (family: string) => (containers(template, family).Secrets ?? []).map((s: { Name: string }) => s.Name).sort();
  for (const role of ['web', 'worker', 'scheduler']) {
    assert.deepEqual(names(`success-meter-hub-${role}`), ['APP_KEY', 'DB_APP_PASSWORD', 'PASSPORT_PRIVATE_KEY', 'PASSPORT_PUBLIC_KEY'], role);
  }
  assert.deepEqual(names('success-meter-hub-migrate'),
    ['APP_KEY', 'DB_APP_PASSWORD', 'DB_PASSWORD', 'DB_USERNAME', 'PASSPORT_PRIVATE_KEY', 'PASSPORT_PUBLIC_KEY']);
});

test('the app is configured for AWS: KMS keys, S3 snapshots, ClamAV, SES, trusted load balancer', () => {
  const { template } = synth({ appImageTag: 'abc123' });
  const env = Object.fromEntries(containers(template, 'success-meter-hub-web').Environment.map((e: { Name: string; Value: unknown }) => [e.Name, e.Value]));
  assert.equal(env.KMS_DRIVER, 'aws');
  assert.equal(env.SNAPSHOTS_DRIVER, 's3');
  assert.equal(env.INGEST_SCANNER, 'clamav');
  assert.equal(env.CLAMD_ADDRESS, 'tcp://clamd.hub.internal:3310');
  assert.equal(env.MAIL_MAILER, 'ses');
  assert.equal(env.TRUSTED_PROXIES, '*');
  assert.equal(env.DB_CONNECTION, 'pgsql_app');
  assert.equal(env.DB_SSLMODE, 'require');
  assert.equal(env.HUB_ISSUER, 'https://hub.example.com');
  assert.deepEqual(containers(template, 'success-meter-hub-web').Command, ['web']);
});

test('services stay stopped until an image is pushed, then run one task each', () => {
  const counts = (template: Template) => Object.values(template.findResources('AWS::ECS::Service')).map(s => s.Properties.DesiredCount);
  assert.deepEqual(counts(synth().template), [0, 0, 0, 0]);
  assert.deepEqual(counts(synth({ appImageTag: 'abc123' }).template), [1, 1, 1, 1]);
});

test('migrations can run a newer image than the app', () => {
  const { template } = synth({ appImageTag: 'old', migrateImageTag: 'new' });
  assert.match(JSON.stringify(containers(template, 'success-meter-hub-migrate').Image), /:new/);
  assert.match(JSON.stringify(containers(template, 'success-meter-hub-web').Image), /:old/);
});

test('the database admits only the app, and clamd only the app', () => {
  const { template } = synth();
  const ingress = Object.values(template.findResources('AWS::EC2::SecurityGroupIngress')).map(r => r.Properties);
  assert.ok(ingress.some(r => r.FromPort === 5432 && r.SourceSecurityGroupId));
  assert.ok(ingress.some(r => r.FromPort === 3310 && r.SourceSecurityGroupId));
  assert.ok(!ingress.some(r => r.CidrIp === '0.0.0.0/0' && (r.FromPort === 5432 || r.FromPort === 3310 || r.FromPort === 8080)));
});

test('the budget counts spend before credits', () => {
  const { template } = synth();
  template.hasResourceProperties('AWS::Budgets::Budget', {
    Budget: Match.objectLike({ BudgetLimit: { Amount: 250, Unit: 'USD' }, CostTypes: Match.objectLike({ IncludeCredit: false }) }),
  });
});

test('GitHub can deploy only from the main branch', () => {
  const { template } = synth();
  template.hasResourceProperties('AWS::IAM::Role', {
    RoleName: 'success-meter-hub-deploy',
    AssumeRolePolicyDocument: { Statement: [Match.objectLike({
      Condition: { StringEquals: {
        'token.actions.githubusercontent.com:aud': 'sts.amazonaws.com',
        'token.actions.githubusercontent.com:sub': 'repo:successmeter/For-Claude-Cloud:ref:refs/heads/main',
      } },
    })] },
  });
});

test('AWS Solutions checks pass (every exception is acknowledged with a reason)', () => {
  const app = new cdk.App();
  cdk.Validations.of(app).addPlugins(new AwsSolutionsChecks(app));
  new HubStack(app, 'Hub', props);
  assert.doesNotThrow(() => app.synth());
});
