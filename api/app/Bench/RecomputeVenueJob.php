<?php
// api/app/Bench/RecomputeVenueJob.php
namespace App\Bench;

use App\Jobs\Contracts\TenantScopedJob;
use App\Jobs\Middleware\RunsWithTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecomputeVenueJob implements ShouldQueue, TenantScopedJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private string $orgId, private string $venueId, private string $earliestChanged) {}

    public function orgId(): string
    {
        return $this->orgId;
    }

    public function middleware(): array
    {
        return [new RunsWithTenantContext];
    }

    public function handle(RecomputeVenue $recompute): void
    {
        $recompute->run($this->venueId, CarbonImmutable::parse($this->earliestChanged));
    }
}
