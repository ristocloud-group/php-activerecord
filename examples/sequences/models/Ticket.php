<?php

/**
 * Declared case: the pk draws from a hand-created sequence the model names
 * with static $sequence. The declaration always wins: the INSERT pulls
 * nextval('ticket_numbers') for the pk. (tickets.id's own default names that
 * sequence too, so without the declaration the library would find it and read
 * the pk back with RETURNING; declare it when the column has no default.)
 *
 * @property int         $id
 * @property string|null $title
 */
class Ticket extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
    public static $sequence = 'ticket_numbers';
}
