<?php

/**
 * @property int    $id
 * @property int    $category_id
 * @property string $name
 * @property float  $price
 * @property float  $secret_cost
 * @property-read Category $category
 * @property-read float    $discounted_price
 */
class Product extends ActiveRecord\Model
{
    public static $belongs_to = [['category']];

    // Exposed to serializers via the 'methods' option.
    public function discounted_price(): float
    {
        return round((float) $this->price * 0.9, 2);
    }
}
