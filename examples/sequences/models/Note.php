<?php

/**
 * Runs on the default (SQLite) connection. The explicit $sequence below is
 * deliberately pointless there: adapters without sequence support ignore the
 * declaration entirely and the pk comes from the regular auto-increment.
 *
 * @property int         $id
 * @property string|null $body
 */
class Note extends ActiveRecord\Model
{
    public static $sequence = 'notes_id_seq';
}
