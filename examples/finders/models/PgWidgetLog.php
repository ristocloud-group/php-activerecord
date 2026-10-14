<?php

/**
 * A Postgres table without a primary key, for the Postgres part of the example.
 *
 * @property int|null    $widget_id
 * @property string|null $message
 */
class PgWidgetLog extends ActiveRecord\Model
{
    public static $connection = 'pgsql';
    public static $table_name = 'pg_widget_logs';
}
