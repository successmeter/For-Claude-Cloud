<?php
// api/app/Pos/CategoryGuesser.php
namespace App\Pos;

/**
 * A first guess at food, drinks or other from a POS category's name (Plan E design E3). Only ever
 * pre-fills the owner's choice; nothing is mapped until the owner saves.
 */
final class CategoryGuesser
{
    private const DRINKS = ['drink', 'drinks', 'beverage', 'beverages', 'bev', 'bar', 'wine', 'wines', 'beer', 'beers', 'cider',
        'spirit', 'spirits', 'cocktail', 'cocktails', 'coffee', 'coffees', 'tea', 'teas', 'juice', 'juices', 'soft', 'softs',
        'liquor', 'alcohol', 'champagne', 'sparkling', 'whisky', 'whiskey', 'gin', 'vodka', 'rum', 'tequila', 'smoothie', 'smoothies',
        'shakes', 'milkshake', 'milkshakes', 'kombucha', 'water', 'aperitif', 'aperitifs', 'digestif', 'digestifs', 'sake', 'tap'];

    private const FOOD = ['food', 'kitchen', 'main', 'mains', 'entree', 'entrees', 'starter', 'starters', 'dessert', 'desserts',
        'breakfast', 'brunch', 'lunch', 'dinner', 'pizza', 'pizzas', 'pasta', 'burger', 'burgers', 'salad', 'salads', 'side', 'sides',
        'snack', 'snacks', 'bakery', 'cake', 'cakes', 'pastry', 'pastries', 'sandwich', 'sandwiches', 'toastie', 'toasties',
        'grill', 'seafood', 'steak', 'steaks', 'kids', 'share', 'sharing', 'plates', 'banquet', 'tapas', 'sushi', 'dumplings',
        'noodles', 'curry', 'curries', 'soup', 'soups', 'bowls', 'meals', 'canapes'];

    public static function guess(string $key, string $name): ?string
    {
        if ($key === 'service_charges') {
            return 'other';
        }
        $words = preg_split('/[^a-z]+/', strtolower(\Illuminate\Support\Str::ascii($name)), flags: PREG_SPLIT_NO_EMPTY);
        $drinks = array_intersect($words, self::DRINKS) !== [];
        $food = array_intersect($words, self::FOOD) !== [];

        // "Food & Beverage" or "Bar snacks" say both: leave it to the owner.
        return match (true) {
            $drinks && ! $food => 'drinks',
            $food && ! $drinks => 'food',
            default => null,
        };
    }
}
