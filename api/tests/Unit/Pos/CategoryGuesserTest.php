<?php
// api/tests/Unit/Pos/CategoryGuesserTest.php

namespace Tests\Unit\Pos;

use App\Pos\CategoryGuesser;
use PHPUnit\Framework\TestCase;

class CategoryGuesserTest extends TestCase
{
    public function test_guesses(): void
    {
        $cases = [
            'Wine' => 'drinks', 'Beers & Ciders' => 'drinks', 'Cocktails' => 'drinks', 'Hot Coffee' => 'drinks', 'Soft Drinks' => 'drinks',
            'BAR' => 'drinks', 'Mains' => 'food', 'Entrées' => 'food', 'Entrees' => 'food', 'Desserts' => 'food', 'Kids Menu' => 'food',
            'Pizza' => 'food', 'Food & Beverage' => null, 'Bar Snacks' => null, 'Merchandise' => null, 'Gift cards' => null,
        ];
        foreach ($cases as $name => $kind) {
            $this->assertSame($kind, CategoryGuesser::guess('K', $name), $name);
        }
        $this->assertSame('other', CategoryGuesser::guess('service_charges', 'Service charges'));
        $this->assertNull(CategoryGuesser::guess('uncategorised', 'Uncategorised'));
    }
}
