<?php
// api/app/Models/AuditLogEntry.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLogEntry extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $table = 'audit_log';
    protected $fillable = ['actor_id', 'org_id', 'action', 'entity_type', 'entity_id', 'ip_address', 'meta'];
    protected $casts = ['meta' => 'array'];

    protected static function booted()
    {
        static::creating(function ($entry) {
            $entry->created_at = now();
        });
    }
}
