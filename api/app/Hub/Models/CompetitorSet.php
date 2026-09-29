<?php
// api/app/Hub/Models/CompetitorSet.php
namespace App\Hub\Models;

use App\Hub\Models\Casts\ToolList;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A subscriber's named group of competitors, shared with the tools listed in `tools`. Writes go
 * through App\Hub\Services\CompetitorSetService (versioning, lock rules, events).
 */
class CompetitorSet extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['org_id', 'name', 'tools', 'created_by'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return [
            'tools' => ToolList::class,
            'version' => 'integer',
            'activated_at' => 'datetime',
            'composition_locked_until' => 'datetime',
        ];
    }

    /** Current members only (removed ones are kept for history). */
    public function members(): HasMany
    {
        return $this->hasMany(CompetitorSetMember::class, 'set_id')->whereNull('removed_at')->orderBy('created_at')->orderBy('id');
    }

    public function isVisibleTo(string $tool): bool
    {
        return in_array($tool, $this->tools, true);
    }
}
