<?php
// api/app/Ingest/Models/IngestionRun.php
namespace App\Ingest\Models;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One upload (later: one POS sync). Plan C design §3.2. */
class IngestionRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'mapping' => 'array',
        'summary' => 'array',
        'problems' => 'array',
        'basis_revision' => 'integer',
        'file_bytes' => 'integer',
        'row_count' => 'integer',
        'committed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'venue_id' => $this->venue_id,
            'method' => $this->method,
            'status' => $this->status,
            // jsonb keeps its own key order; answer in a fixed one.
            'mapping' => self::ordered($this->mapping, \App\Ingest\Upload\Mapping::KEYS),
            'summary' => self::ordered($this->summary, ['rows', 'new', 'changed', 'unchanged', 'covers', 'problems', 'problems_truncated', 'first_date', 'last_date']),
            'problems' => array_map(fn ($p) => self::ordered($p, ['row', 'column', 'code']), $this->problems ?? []),
            'row_count' => $this->row_count,
            'file_sha256' => $this->file_sha256,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'committed_at' => $this->committed_at?->toIso8601String(),
        ];
    }

    private static function ordered(?array $values, array $keys): ?array
    {
        return $values === null ? null : array_merge(array_intersect_key(array_flip($keys), $values), $values);
    }
}
