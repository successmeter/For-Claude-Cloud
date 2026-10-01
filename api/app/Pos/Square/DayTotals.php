<?php
// api/app/Pos/Square/DayTotals.php
namespace App\Pos\Square;

use Carbon\CarbonImmutable;

/**
 * Square orders -> business-day totals per category (Plan E design §3, E4). Each order counts on the
 * business day it closed in the venue's time zone, less the trading-day cutoff. A line counts its
 * total after discounts, tax included (Square AU prices include GST); service charges are their own
 * category; returns subtract from their category on the day of the return; tips are left out.
 * Only aggregates are kept: no customer, card or line-level detail.
 */
final class DayTotals
{
    /** @var array<string, array<string, array{name: string, cents: int, qty: float}>> */
    private array $categories = [];

    /** @var array<string, int> */
    private array $orders = [];

    public function __construct(private string $timezone, private int $cutoffMinutes, private SquareCatalog $catalog) {}

    public static function businessDate(string $closedAt, string $timezone, int $cutoffMinutes): string
    {
        return CarbonImmutable::parse($closedAt)->setTimezone($timezone)->subMinutes($cutoffMinutes)->toDateString();
    }

    public function add(array $order): void
    {
        if (($order['state'] ?? null) !== 'COMPLETED' || ! isset($order['closed_at'])) {
            return;
        }
        $day = self::businessDate($order['closed_at'], $this->timezone, $this->cutoffMinutes);

        $lines = $order['line_items'] ?? [];
        foreach ($lines as $line) {
            $this->put($day, $this->catalog->categoryOf($line['catalog_object_id'] ?? null), self::money($line['total_money'] ?? null), (float) ($line['quantity'] ?? 0));
        }
        foreach ($order['service_charges'] ?? [] as $charge) {
            $this->put($day, SquareCatalog::SERVICE_CHARGES, self::money($charge['total_money'] ?? null), 0);
        }
        foreach ($order['returns'] ?? [] as $return) {
            foreach ($return['return_line_items'] ?? [] as $line) {
                $this->put($day, $this->catalog->categoryOf($line['catalog_object_id'] ?? null), -self::money($line['total_money'] ?? null), -(float) ($line['quantity'] ?? 0));
            }
            foreach ($return['return_service_charges'] ?? [] as $charge) {
                $this->put($day, SquareCatalog::SERVICE_CHARGES, -self::money($charge['total_money'] ?? null), 0);
            }
        }
        if ($lines !== []) {
            $this->orders[$day] = ($this->orders[$day] ?? 0) + 1;
        }
    }

    /** @return array<string, array<string, array{name: string, cents: int, qty: float}>> day => key => totals */
    public function categories(): array
    {
        ksort($this->categories);

        return $this->categories;
    }

    /** @return array<string, int> day => completed sales orders */
    public function orders(): array
    {
        return $this->orders;
    }

    private function put(string $day, array $category, int $cents, float $qty): void
    {
        [$key, $name] = $category;
        $this->categories[$day][$key] ??= ['name' => $name, 'cents' => 0, 'qty' => 0.0];
        $this->categories[$day][$key]['cents'] += $cents;
        $this->categories[$day][$key]['qty'] += $qty;
    }

    private static function money(?array $money): int
    {
        return (int) ($money['amount'] ?? 0);
    }
}
