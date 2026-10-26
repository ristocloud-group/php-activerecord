<?php

/**
 * A table with a composite primary key (hall, seat).
 *
 * @property string      $hall
 * @property string      $seat
 * @property string|null $guest
 * @property-read \ActiveRecord\Errors $errors
 */
class HallSeat extends ActiveRecord\Model
{
    public static $table_name = 'hall_seats';

    /** @var array<int, array<int|string, mixed>> */
    public static $validates_uniqueness_of = [
        ['guest'],
    ];
}
