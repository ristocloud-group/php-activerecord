<?php

/**
 * Renamed-table case: seats was created as seats_old, so its serial pk still
 * draws from seats_old_id_seq. The library reads that real sequence name from
 * the column's default instead of guessing seats_id_seq.
 *
 * @property int         $id
 * @property string|null $label
 */
class Seat extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
}
