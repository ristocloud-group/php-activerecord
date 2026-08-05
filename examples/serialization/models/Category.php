<?php

/**
 * @property int    $id
 * @property string $name
 * @property-read array<int, Product> $products
 */
class Category extends ActiveRecord\Model
{
    /** @var array<int, array<int|string, mixed>> */
    public static $has_many = [['products']];
}
