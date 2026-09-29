<?php
// api/app/Hub/Models/OrgToolLink.php
namespace App\Hub\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrgToolLink extends Model
{
    use HasUuids;

    protected $fillable = ['org_id', 'tool', 'external_tenant_ref', 'linked_by', 'linked_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
