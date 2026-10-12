<?php

// api/tests/Feature/Pos/QueueRetryWindowTest.php

namespace Tests\Feature\Pos;

use App\Pos\Square\SyncSquareLocation;
use Tests\TestCase;

class QueueRetryWindowTest extends TestCase
{
    // A job still running when retry_after passes is handed to a second worker, so the window must outlast the
    // longest job, or one venue's sync runs twice at once.
    public function test_the_database_queue_waits_longer_than_the_longest_job(): void
    {
        $timeout = (new \ReflectionClass(SyncSquareLocation::class))->getProperty('timeout')->getDefaultValue();

        $this->assertGreaterThan($timeout, config('queue.connections.database.retry_after'));
    }
}
