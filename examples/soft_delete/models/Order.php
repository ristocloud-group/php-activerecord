<?php

use ActiveRecord\Model;
use ActiveRecord\SoftDeletable;
use ActiveRecord\SoftDeletes;

/**
 * Both the interface and the trait are required: either alone is a ConfigException.
 *
 * @property int         $id
 * @property string      $number
 * @property string|null $deleted_at
 */
class Order extends Model implements SoftDeletable
{
    use SoftDeletes;
}
