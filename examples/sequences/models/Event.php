<?php

/**
 * Convention case: a serial pk on Postgres owns {table}_{pk}_seq
 * (events_id_seq here), which the adapter introspects automatically —
 * no $sequence declaration needed.
 *
 * @property int         $id
 * @property string|null $title
 */
class Event extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
}
