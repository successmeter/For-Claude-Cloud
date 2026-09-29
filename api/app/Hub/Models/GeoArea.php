<?php
// api/app/Hub/Models/GeoArea.php
namespace App\Hub\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Read-only reference data for the app (app_user has SELECT only). */
class GeoArea extends Model
{
    use HasUuids;

    public $timestamps = false;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
