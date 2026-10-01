<?php
// api/app/Pos/Square/SquareCatalog.php
namespace App\Pos\Square;

/**
 * Item variation -> reporting category, from one pass over the catalog (Plan E design §3: looked up
 * once per sync). A variation Square no longer knows, or an ad hoc amount, is "uncategorised".
 */
final class SquareCatalog
{
    public const UNCATEGORISED = ['uncategorised', 'Uncategorised'];

    public const SERVICE_CHARGES = ['service_charges', 'Service charges'];

    /** @param array<string, array{0: string, 1: string}> $byVariation */
    private function __construct(private array $byVariation) {}

    public static function load(SquareClient $client): self
    {
        $categoryNames = [];
        $itemCategory = [];
        foreach ($client->catalog(['ITEM', 'CATEGORY']) as $object) {
            if (($object['type'] ?? null) === 'CATEGORY') {
                $categoryNames[$object['id']] = (string) ($object['category_data']['name'] ?? $object['id']);
            } elseif (($object['type'] ?? null) === 'ITEM') {
                $item = $object['item_data'] ?? [];
                $category = $item['reporting_category']['id'] ?? $item['category_id'] ?? ($item['categories'][0]['id'] ?? null);
                foreach ($item['variations'] ?? [] as $variation) {
                    $itemCategory[$variation['id']] = $category;
                }
            }
        }

        $byVariation = [];
        foreach ($itemCategory as $variation => $category) {
            $byVariation[$variation] = $category === null ? self::UNCATEGORISED
                : [mb_substr((string) $category, 0, 200), mb_substr($categoryNames[$category] ?? 'Unknown category', 0, 200)];
        }

        return new self($byVariation);
    }

    /** @return array{0: string, 1: string} [key, name] */
    public function categoryOf(?string $variationId): array
    {
        return $variationId === null ? self::UNCATEGORISED : ($this->byVariation[$variationId] ?? self::UNCATEGORISED);
    }

    /** For tests: variation id => [category key, name]. */
    public static function fromMap(array $byVariation): self
    {
        return new self($byVariation);
    }
}
