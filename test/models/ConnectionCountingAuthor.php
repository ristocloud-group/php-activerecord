<?php

/**
 * An Author whose connection() override counts its calls, as a consumer's base class overrides the
 * public static Model::connection(): the legacy count() keeps calling that override.
 */
class ConnectionCountingAuthor extends Author
{
    public static $table_name = 'authors';

    /** How many times connection() was called since a test last reset the counter */
    public static int $connection_calls = 0;

    public static function connection(): ActiveRecord\Connection
    {
        self::$connection_calls++;

        return parent::connection();
    }
}
