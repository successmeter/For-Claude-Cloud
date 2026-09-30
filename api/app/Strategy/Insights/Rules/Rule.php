<?php
// api/app/Strategy/Insights/Rules/Rule.php
namespace App\Strategy\Insights\Rules;

use App\Strategy\Insights\Finding;
use App\Strategy\Insights\VenueHistory;

abstract class Rule
{
    public const TYPE = '';

    /** @param array $config config('insights') */
    public function __construct(protected array $config) {}

    /** @return list<Finding> */
    abstract public function findings(VenueHistory $history): array;

    protected function setting(string $key): mixed
    {
        return $this->config[static::TYPE][$key];
    }

    protected static function direction(float $pct): string
    {
        return $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
    }
}
