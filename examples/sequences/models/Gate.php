<?php

/**
 * Convention case: gates.id has no default, so nothing tells the library which
 * sequence numbers it; it falls back to the {table}_{pk}_seq name (gates_id_seq,
 * created by hand) and the INSERT pulls nextval() from it.
 *
 * @property int         $id
 * @property string|null $name
 */
class Gate extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
}
