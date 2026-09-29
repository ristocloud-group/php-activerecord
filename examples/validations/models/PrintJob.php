<?php

/**
 * @property int         $id
 * @property string|null $binding
 * @property-read \ActiveRecord\Errors $errors
 */
class PrintJob extends ActiveRecord\Model
{
    // A custom message: %s is replaced by the rejected value.
    /** @var array<int, array<int|string, mixed>> */
    public static $validates_inclusion_of = [
        ['binding', 'in' => ['hardcover', 'paperback'], 'message' => "'%s' is not offered"],
    ];
}
