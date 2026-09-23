<?php
// api/app/Services/Audit/AuditLogger.php
namespace App\Services\Audit;

use App\Models\AuditLogEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function record(string $action, string $entityType, string $entityId, ?string $orgId = null, array $meta = []): void
    {
        AuditLogEntry::create([
            'actor_id' => Auth::id(),
            'org_id' => $orgId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => Request::ip(),
            'meta' => $meta,
        ]);
    }
}
