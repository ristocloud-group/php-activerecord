<?php

/**
 * @property int    $id
 * @property string $name
 * @property string $category
 * @property float  $price
 * @property int    $in_stock
 *
 * @method static Widget|null        find_by_name(string $name)
 * @method static array<int, Widget> find_all_by_category(string $category)
 * @method static Widget|null        find_by_category_and_in_stock(string $category, int $in_stock)
 */
class Widget extends ActiveRecord\Model
{
    // A reusable static scope.
    /** @return array<int, Widget> */
    public static function cheap(): array
    {
        return static::all(['conditions' => ['price < ?', 10.0], 'order' => 'price asc']);
    }
}
