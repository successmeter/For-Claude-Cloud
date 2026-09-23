<?php
// api/app/Models/Venue.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'address', 'timezone', 'segment', 'cuisine',
        'market_id', 'business_day_cutoff', 'gst_inclusive_default',
    ];

    public function org()
    {
        return $this->belongsTo(Org::class);
    }
}
