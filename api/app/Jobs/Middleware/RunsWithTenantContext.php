<?php
// api/app/Jobs/Middleware/RunsWithTenantContext.php
namespace App\Jobs\Middleware;

use App\Jobs\Contracts\TenantScopedJob;
use App\Services\Tenancy\TenantContext;
use Closure;
use LogicException;

class RunsWithTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof TenantScopedJob) {
            throw new LogicException($job::class.' uses RunsWithTenantContext but does not implement TenantScopedJob.');
        }

        return TenantContext::run($job->orgId(), fn () => $next($job));
    }
}
