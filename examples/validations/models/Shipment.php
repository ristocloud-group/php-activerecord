<?php

/**
 * @property int         $id
 * @property string|null $tracking_number
 * @property-read \ActiveRecord\Errors $errors
 */
class Shipment extends ActiveRecord\Model
{
    // A carrier's 64-bit tracking number, kept in a TEXT column: an integer or a
    // plain decimal string is compared exactly, so a bound like PHP_INT_MAX is not
    // rounded to a float.
    /** @var array<int, array<int|string, mixed>> */
    public static $validates_numericality_of = [
        ['tracking_number', 'only_integer' => true, 'greater_than' => 0, 'less_than' => PHP_INT_MAX],
    ];
}
