<?php

/**
 * The `users` table again, with optional fields: `allow_blank` skips a rule when
 * the value is blank (null, '' or an empty array) and still validates any other value.
 *
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property-read \ActiveRecord\Errors $errors
 */
class Signup extends ActiveRecord\Model
{
    public static $table_name = 'users';

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_format_of = [
        ['email', 'with' => '/\A[^@\s]+@[^@\s]+\.[^@\s]+\z/', 'allow_blank' => true],
    ];

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_inclusion_of = [
        // `topic` is not a column: a virtual form field, set with assign_attribute().
        ['topic', 'in' => ['php', 'sql'], 'allow_blank' => true],
    ];
}
