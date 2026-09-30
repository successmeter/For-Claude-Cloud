<?php
// api/app/Hub/Models/Casts/ToolList.php
namespace App\Hub\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Postgres text[] <-> PHP list for competitor_sets.tools. Values are tool names (lowercase
 * letters), so the array literal needs no quoting; anything else is refused before it reaches SQL.
 */
class ToolList implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        $inner = trim((string) $value, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        foreach ($value as $tool) {
            if (! is_string($tool) || ! preg_match('/^[a-z]+$/', $tool)) {
                throw new InvalidArgumentException('Invalid tool name.');
            }
        }

        return '{'.implode(',', array_values($value)).'}';
    }
}
