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
  webApiDomain: 'web-api.example.com',
  webFrontendUrl: 'https://web.example.com',
  webGithubRepo: 'successmeter/traffic-dashboard',
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
  template.hasResourceProperties('AWS::CertificateManager::Certificate', { DomainName: 'hub.example.com', SubjectAlternativeNames: ['web-api.example.com'], ValidationMethod: 'DNS' });
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

test('Square is off until its application id is set, then its secret comes from the app secret', () => {
  const off = synth({ appImageTag: 'abc123' }).template;
  const offEnv = Object.fromEntries(containers(off, 'success-meter-hub-worker').Environment.map((e: { Name: string; Value: unknown }) => [e.Name, e.Value]));
  assert.equal(offEnv.SQUARE_APPLICATION_ID, undefined);

  const on = synth({ appImageTag: 'abc123', squareApplicationId: 'sq0idp-app', squareEnvironment: 'production' }).template;
  for (const role of ['web', 'worker', 'scheduler']) {
    const c = containers(on, `success-meter-hub-${role}`);
    const env = Object.fromEntries(c.Environment.map((e: { Name: string; Value: unknown }) => [e.Name, e.Value]));
    assert.equal(env.SQUARE_APPLICATION_ID, 'sq0idp-app', role);
    assert.equal(env.SQUARE_ENVIRONMENT, 'production', role);
    assert.ok(c.Secrets.some((s: { Name: string }) => s.Name === 'SQUARE_APPLICATION_SECRET'), role);
  }
  assert.throws(() => synth({ squareApplicationId: 'x', squareEnvironment: 'live' as 'production' }), /squareEnvironment/);
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
  assert.equal(env.SANCTUM_STATEFUL_DOMAINS, new URL(env.APP_FRONTEND_URL).host);
  assert.equal(env.SESSION_SECURE_COOKIE, 'true');
  assert.deepEqual(containers(template, 'success-meter-hub-web').Command, ['web']);
});

test('services stay stopped until an image is pushed, then run one task each', () => {
  // Desired count by service (logical id without its hash).
  const counts = (template: Template) => Object.fromEntries(Object.entries(template.findResources('AWS::ECS::Service'))
    .map(([id, r]) => [id.replace(/Service[0-9A-F]{8}$|[0-9A-F]{8}$/, ''), r.Properties.DesiredCount]));
  assert.deepEqual(counts(synth().template), { Web: 0, Worker: 0, Scheduler: 0, WebApi: 0, Clamd: 0 });
  // The Web API waits for its own image.
  assert.deepEqual(counts(synth({ appImageTag: 'abc123' }).template), { Web: 1, Worker: 1, Scheduler: 1, WebApi: 0, Clamd: 1 });
  assert.deepEqual(counts(synth({ appImageTag: 'abc123', webImageTag: 'def456' }).template), { Web: 1, Worker: 1, Scheduler: 1, WebApi: 1, Clamd: 1 });
});

test('the Web API: its own host on the load balancer, its own database and secrets', () => {
  const { template } = synth({ appImageTag: 'abc123', webImageTag: 'web1' });
  template.hasResourceProperties('AWS::ElasticLoadBalancingV2::ListenerRule', {
    Conditions: [Match.objectLike({ Field: 'host-header', HostHeaderConfig: { Values: ['web-api.example.com'] } })],
  });
  template.hasResourceProperties('AWS::ElasticLoadBalancingV2::TargetGroup', { HealthCheckPath: '/health', Port: 8787 });

  const api = containers(template, 'success-meter-web-api');
  const env = Object.fromEntries(api.Environment.map((e: { Name: string; Value: unknown }) => [e.Name, e.Value]));
  assert.equal(env.PGDATABASE, 'web');
  assert.equal(env.PGUSER, 'web_app');
  assert.equal(env.PGSSLMODE, 'verify-full');
  assert.equal(env.NODE_ENV, 'production');
  assert.equal(env.FRONTEND_ORIGIN, 'https://web.example.com');
  assert.equal(env.HUB_ISSUER, 'https://hub.example.com');
  assert.equal(env.HUB_REDIRECT_URI, 'https://web.example.com/auth/callback');
  assert.match(JSON.stringify(api.Image), /WebRepo.*:web1/);
  const secrets = (family: string) => (containers(template, family).Secrets ?? []).map((x: { Name: string }) => x.Name).sort();
  assert.deepEqual(secrets('success-meter-web-api'),
    ['GOOGLE_SERVICE_ACCOUNT_JSON', 'HUB_CLIENT_ID', 'HUB_CLIENT_SECRET', 'HUB_WEBHOOK_SECRET', 'PGPASSWORD', 'SESSION_SECRET']);
  // Only the Web migrate task holds the database owner's credentials, and it needs no Hub secrets.
  assert.deepEqual(secrets('success-meter-web-migrate'), ['DB_ADMIN_PASSWORD', 'DB_ADMIN_USER', 'PGPASSWORD']);
  assert.deepEqual(containers(template, 'success-meter-web-migrate').Command, ['migrate']);
});

test('the Web repo can only push Web images, from its main branch', () => {
  const { template } = synth();
  template.hasResourceProperties('AWS::IAM::Role', {
    RoleName: 'success-meter-web-publish',
    AssumeRolePolicyDocument: { Statement: [Match.objectLike({
      Condition: { StringEquals: {
        'token.actions.githubusercontent.com:aud': 'sts.amazonaws.com',
        'token.actions.githubusercontent.com:sub': 'repo:successmeter/traffic-dashboard:ref:refs/heads/main',
      } },
    })] },
  });
  const policies = Object.values(template.findResources('AWS::IAM::Policy'))
    .filter(p => JSON.stringify(p.Properties.Roles).includes('WebPublishRole'));
  const text = JSON.stringify(policies);
  assert.match(text, /ecr:PutImage/);
  assert.match(text, /WebRepo[0-9A-F]{8}/);
  assert.doesNotMatch(text, /"Repo[0-9A-F]{8}"/, 'nothing on the Hub image repository');
  assert.doesNotMatch(text, /iam:PassRole|ecs:|secretsmanager:/);
});

test('the Hub tasks may write only the Web secret (to hand over its Hub credentials)', () => {
  const { template } = synth();
  const text = JSON.stringify(template.findResources('AWS::IAM::Policy'));
  const statements = [...text.matchAll(/"Action":\["secretsmanager:GetSecretValue","secretsmanager:PutSecretValue"\],"Effect":"Allow","Resource":\{"Ref":"(\w+)"\}/g)];
  assert.equal(statements.length, 1);
  assert.match(statements[0][1], /^WebSecret/);
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
  assert.equal(ingress.filter(r => r.FromPort === 5432).length, 2, 'the Hub app and the Web API only');
  assert.ok(!ingress.some(r => r.CidrIp === '0.0.0.0/0' && [5432, 3310, 8080, 8787].includes(r.FromPort)));
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
