<?php

/**
 * @property int         $id
 * @property string|null $weight_kg
 * @property-read \ActiveRecord\Errors $errors
 */
class Parcel extends ActiveRecord\Model
{
    // weight_kg is optional: a blank value ('' or null) skips the rule, anything else must be > 0.
    /** @var array<int, array<int|string, mixed>> */
    public static $validates_numericality_of = [
        ['weight_kg', 'greater_than' => 0, 'allow_blank' => true],
    ];
}
