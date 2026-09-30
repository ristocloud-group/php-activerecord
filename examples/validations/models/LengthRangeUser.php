<?php

/**
 * Same `users` table as User, with length ranges that use a zero or an exact bound.
 *
 * @property int    $id
 * @property string $name
 * @property string $role
 * @property-read \ActiveRecord\Errors $errors
 */
class LengthRangeUser extends ActiveRecord\Model
{
    public static $table_name = 'users';

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_length_of = [
        ['name', 'within' => [3, 3]],  // exactly 3 characters (e.g. initials)
        ['role', 'within' => [0, 10]], // optional: empty is fine, at most 10 characters
    ];
}
