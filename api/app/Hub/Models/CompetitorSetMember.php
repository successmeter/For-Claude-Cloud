<?php
// api/app/Hub/Models/CompetitorSetMember.php
namespace App\Hub\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A competitor business in a set. Tool-neutral: no GA4 id, no venue match here (design B5).
 * market_id is internal and never leaves the Hub (contract v1 members carry no matching data).
 */
class CompetitorSetMember extends Model
{
    use HasUuids;

    protected $fillable = ['org_id', 'set_id', 'name', 'website_url', 'location_text', 'cuisine', 'market_id'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }
}
