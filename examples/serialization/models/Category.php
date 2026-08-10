<?php

/**
 * @property int    $id
 * @property string $name
 * @property-read array<int, Product> $products
 */
class Category extends ActiveRecord\Model
{
    public static $has_many = [['products']];
}
