<?php
// api/app/Strategy/Insights/Finding.php
namespace App\Strategy\Insights;

use Carbon\CarbonImmutable;

/** One fact about a venue's own history (schemas/findings.v1.json). The engine assigns its id. */
final class Finding
{
    /** @param array<string, int|float|string|null> $figures */
    public function __construct(
        public readonly string $type,
        public readonly bool $material,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly array $figures,
        public readonly ?string $direction = null,
    ) {}

    public function toArray(): array
    {
        return ['type' => $this->type, 'material' => $this->material]
            + ($this->direction === null ? [] : ['direction' => $this->direction])
            + ['period' => ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()], 'figures' => $this->figures];
    }
}
