<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

/**
 * Both the interface and the trait are required: either alone is a ConfigException. Named ShopOrder, on the
 * orders table, so that it does not clash with the Order model of examples/orders.
 *
 * @property int                         $id
 * @property string                      $number
 * @property \ActiveRecord\DateTime|null $deleted_at
 */
class ShopOrder extends Model implements SoftDeletable
{
    use SoftDeletes;

    public static $table_name = 'orders';
}
