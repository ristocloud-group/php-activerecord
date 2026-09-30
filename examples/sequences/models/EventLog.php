<?php

/**
 * No-primary-key case: an append-only log table with no pk column, so the
 * {table}_{pk}_seq convention has nothing to name and the table gets no
 * sequence at all.
 *
 * @property int|null    $event_id
 * @property string|null $message
 */
class EventLog extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
}
