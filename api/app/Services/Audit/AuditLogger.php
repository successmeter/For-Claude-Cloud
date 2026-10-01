<?php
// api/app/Services/Audit/AuditLogger.php
namespace App\Services\Audit;

use App\Models\AuditLogEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /** $actorId: for requests without a signed-in user that act for a known one (an OAuth callback). */
    public function record(string $action, string $entityType, string $entityId, ?string $orgId = null, array $meta = [], ?int $actorId = null): void
    {
        AuditLogEntry::create([
            'actor_id' => $actorId ?? Auth::id(),
            'org_id' => $orgId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => Request::ip(),
            'meta' => $meta,
        ]);
    }
}
