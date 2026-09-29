<?php
// api/app/Jobs/Contracts/TenantScopedJob.php
namespace App\Jobs\Contracts;

/**
 * A queued job that reads or writes tenant tables. It must carry the org it acts for and list
 * App\Jobs\Middleware\RunsWithTenantContext in middleware(), so RLS scopes it to that org
 * (05-security.md §5.4: every queued job carries its org_id and sets tenant context).
 */
interface TenantScopedJob
{
    public function orgId(): string;
}
