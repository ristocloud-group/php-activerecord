<?php

/**
 * An Author whose connection() override counts its calls, as a consumer's base class overrides the
 * public static Model::connection(): the legacy find by pk and update_all() / delete_all() keep calling
 * that override; count() and the builder run on the Table's connection.
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
