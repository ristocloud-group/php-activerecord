<?php

/**
 * @property int               $id
 * @property string|null       $binding
 * @property float|string|null $first_page (a NUMERIC column: reads back as a float)
 * @property float|string|null $last_page
 * @property-read \ActiveRecord\Errors $errors
 */
class PrintJob extends ActiveRecord\Model
{
    // A custom message: %s is replaced by the rejected value.
    /** @var array<int, array<int|string, mixed>> */
    public static $validates_inclusion_of = [
        ['binding', 'in' => ['hardcover', 'paperback'], 'message' => "'%s' is not offered"],
    ];

    // Whole sheets only: a range starts on a right-hand (odd) page and ends on
    // a left-hand (even) one.
    /** @var array<int, array<int|string, mixed>> */
    public static $validates_numericality_of = [
        ['first_page', 'odd' => true],
        ['last_page', 'even' => true],
    ];
}
