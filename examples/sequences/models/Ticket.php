<?php

/**
 * Non-convention case: the pk draws from a hand-created sequence whose name
 * the {table}_{pk}_seq convention cannot guess, so the model must declare it.
 *
 * @property int         $id
 * @property string|null $title
 */
class Ticket extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
    public static $sequence = 'ticket_numbers';
}
